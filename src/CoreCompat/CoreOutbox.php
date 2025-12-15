<?php
declare(strict_types=1);

namespace BlackCat\Messaging\CoreCompat;

use BlackCat\Core\Database;
use BlackCat\Database\Packages\EventOutbox\Definitions as EventOutboxDefinitions;
use BlackCat\Database\Packages\EventOutbox\Repository\EventOutboxRepository;
use BlackCat\Messaging\Support\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Backwards-compat for `BlackCat\Core\Messaging\Outbox`.
 *
 * Stores legacy payload/headers/notifications inside `event_outbox.payload` JSON.
 */
final class CoreOutbox
{
    private const CLAIM_LEASE_SECONDS = 300;

    private readonly EventOutboxRepository $repo;

    public function __construct(
        private readonly Database $db,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $table = 'outbox'
    ) {
        $this->repo = new EventOutboxRepository($db);
    }

    /**
     * @param array<string,mixed>      $payload
     * @param array<string,string|int> $headers
     */
    public function enqueue(
        string $topic,
        array $payload,
        ?string $partitionKey = null,
        ?string $dedupKey = null,
        array $headers = [],
        ?\DateTimeInterface $availableAt = null,
        array $notifications = []
    ): void {
        $topic = trim($topic);
        if ($topic === '') {
            throw new \InvalidArgumentException('Outbox topic must not be empty.');
        }

        $entityTable = $this->normalizeFixedString($this->table, 64, 'outbox');
        $eventType = $this->normalizeFixedString($topic, 100, $topic);

        $entityPk = $partitionKey !== null ? trim($partitionKey) : '';
        if ($entityPk === '') {
            $entityPk = '-';
        }
        $entityPk = $this->normalizeFixedString($entityPk, 64, '-');

        $eventKey = null;
        if ($dedupKey !== null && trim($dedupKey) !== '') {
            $eventKey = Uuid::normalize($dedupKey, $entityTable . '|' . $eventType);
        }
        $eventKey ??= Uuid::v4();

        $storedPayload = [
            'payload' => $payload,
            'headers' => $headers,
            'notifications' => $notifications,
        ];

        $storedPayloadJson = json_encode($storedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($storedPayloadJson === false) {
            throw new \RuntimeException('Failed to encode outbox payload/headers.');
        }

        $nextAttemptAt = $availableAt ? $this->formatUtc($availableAt) : null;

        try {
            $this->repo->insert([
                'event_key' => $eventKey,
                'entity_table' => $entityTable,
                'entity_pk' => $entityPk,
                'event_type' => $eventType,
                'payload' => $storedPayloadJson,
                'next_attempt_at' => $nextAttemptAt,
            ]);
        } catch (\Throwable $e) {
            if ($dedupKey !== null && $this->isDuplicateError($e)) {
                $this->logger?->info('outbox-duplicate', ['topic' => $eventType, 'dedup' => $dedupKey]);
                return;
            }
            throw $e;
        }
    }

    /**
     * Flush pending events via a sender/transport.
     *
     * @param callable|object $sender Callable OR object with ->send(array $row): bool
     */
    public function flush(callable|object $sender, int $limit = 100): int
    {
        $limit = max(1, $limit);
        $callback = $this->resolveSenderCallback($sender);

        $rows = $this->claimBatch($limit);
        $sent = 0;

        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            if ($id <= 0) {
                continue;
            }

            try {
                $legacy = $this->toLegacyRow($row);
                $ok = (bool)$callback($legacy);
                if (!$ok) {
                    throw new \RuntimeException('Sender reported failure.');
                }

                $this->markSent($id);
                $this->dispatchNotifications($row);
                $sent++;
            } catch (\Throwable $e) {
                $this->markFailed($row, $e);
            }
        }

        return $sent;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function claimBatch(int $limit): array
    {
        $meta = ['component' => 'outbox', 'entity_table' => $this->table];
        return (array)$this->db->txWithMeta(
            function (Database $db) use ($limit): array {
                $tbl = $db->quoteIdent(EventOutboxDefinitions::table());
                $now = $this->utcNowSql();
                $for = ($db->isPg() || $db->isMysql()) ? 'FOR UPDATE SKIP LOCKED' : 'FOR UPDATE';

                $sql = "
                    SELECT id, event_key, entity_table, entity_pk, event_type, payload, status, attempts, next_attempt_at, processed_at, producer_node, created_at
                      FROM {$tbl}
                     WHERE entity_table = :t
                       AND status IN ('pending','failed')
                       AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
                     ORDER BY id
                     LIMIT {$limit}
                     {$for}";

                $rows = $db->fetchAll($sql, [':t' => $this->normalizeFixedString($this->table, 64, 'outbox'), ':now' => $now]);
                if ($rows === []) {
                    return [];
                }

                $leaseAt = $this->formatUtc((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . self::CLAIM_LEASE_SECONDS . ' seconds'));
                foreach ($rows as $r) {
                    if (!is_array($r) || !isset($r['id'])) {
                        continue;
                    }
                    $id = (int)$r['id'];
                    if ($id <= 0) {
                        continue;
                    }
                    $this->repo->updateById($id, ['next_attempt_at' => $leaseAt]);
                }

                return $rows;
            },
            $meta,
            ['readOnly' => false]
        );
    }

    private function markSent(int $id): void
    {
        $this->repo->updateById($id, [
            'status' => 'sent',
            'processed_at' => $this->utcNowSql(),
            'next_attempt_at' => null,
        ]);
    }

    private function markFailed(array $row, \Throwable $e): void
    {
        $id = isset($row['id']) ? (int)$row['id'] : 0;
        if ($id <= 0) {
            return;
        }

        $attempts = (int)($row['attempts'] ?? 0);
        $wait = min(3600, (int)pow(2, min(10, $attempts)) + random_int(0, 15));
        $nextAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $wait . ' seconds')
            ->format('Y-m-d H:i:s.u');

        $this->repo->updateById($id, [
            'status' => 'failed',
            'attempts' => $attempts + 1,
            'next_attempt_at' => $nextAt,
        ]);

        $this->logger?->warning('outbox-send-failed', [
            'id' => $id,
            'topic' => $row['event_type'] ?? null,
            'next_in_s' => $wait,
            'error' => $e->getMessage(),
        ]);
    }

    private function dispatchNotifications(array $row): void
    {
        $stored = $this->decodeStoredPayload($row['payload'] ?? null);
        $items = $stored['notifications'] ?? null;
        if (!is_array($items) || $items === []) {
            return;
        }

        foreach ($items as $note) {
            if (!is_array($note)) {
                continue;
            }
            $type = $note['type'] ?? null;
            if ($type === 'webhook' && isset($note['url'])) {
                $this->notifyWebhook((string)$note['url'], is_array($note['payload'] ?? null) ? (array)$note['payload'] : []);
            }
        }
    }

    private function notifyWebhook(string $url, array $payload): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $status >= 400) {
            $this->logger?->warning('outbox-webhook-failed', ['url' => $url, 'status' => $status]);
        }
        curl_close($ch);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeStoredPayload(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function toLegacyRow(array $row): array
    {
        $stored = $this->decodeStoredPayload($row['payload'] ?? null);
        $payload = $stored['payload'] ?? [];
        $headers = $stored['headers'] ?? [];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'id' => $row['id'] ?? null,
            'topic' => $row['event_type'] ?? null,
            'part_key' => $row['entity_pk'] ?? null,
            'payload' => $payloadJson === false ? '{}' : $payloadJson,
            'headers' => $headersJson === false ? '{}' : $headersJson,
            'attempts' => $row['attempts'] ?? 0,
        ];
    }

    private function resolveSenderCallback(callable|object $sender): callable
    {
        if (is_object($sender) && method_exists($sender, 'send')) {
            return [$sender, 'send'];
        }
        if (is_callable($sender)) {
            return $sender;
        }
        throw new \InvalidArgumentException('Invalid outbox sender: expected callable or object with send().');
    }

    private function isDuplicateError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage() ?? '');
        return str_contains($message, 'duplicate') || str_contains($message, 'unique');
    }

    private function utcNowSql(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function formatUtc(\DateTimeInterface $dt): string
    {
        $imm = $dt instanceof \DateTimeImmutable ? $dt : \DateTimeImmutable::createFromInterface($dt);
        return $imm->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function normalizeFixedString(string $value, int $maxLen, string $fallback): string
    {
        $v = trim($value);
        if ($v === '') {
            $v = $fallback;
        }
        if (strlen($v) <= $maxLen) {
            return $v;
        }
        return substr(hash('sha256', $v), 0, min($maxLen, 64));
    }
}

