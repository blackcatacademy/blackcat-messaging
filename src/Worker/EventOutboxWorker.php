<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Worker;

use BlackCat\Core\Database;
use BlackCat\Database\Crypto\IngressLocator;
use BlackCat\Database\Packages\EventOutbox\Repository\EventOutboxRepository;
use BlackCat\Messaging\Config\EventOutboxWorkerConfig;
use BlackCat\Messaging\Contracts\TransportInterface;
use BlackCat\Messaging\Support\MessageEnvelope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class EventOutboxWorker
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Database $db,
        private readonly EventOutboxRepository $outbox,
        private readonly TransportInterface $transport,
        private readonly EventOutboxWorkerConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return array{processed:int,sent:int,failed:int,skipped:int}
     */
    public function runOnce(): array
    {
        $limit = $this->config->batchSize();
        $entityTable = trim($this->config->entityTable());

        $sql = "SELECT id FROM vw_event_outbox_due";
        $params = ['lim' => $limit];
        if ($entityTable !== '') {
            $sql .= " WHERE entity_table = :entity_table";
            $params['entity_table'] = $entityTable;
        }
        $sql .= " ORDER BY id ASC LIMIT :lim";

        $ids = $this->db->fetchAll($sql, $params);

        $processed = 0;
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($ids as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $processed++;

            $locked = $this->claim($id);
            if ($locked === null) {
                $skipped++;
                continue;
            }

            try {
                $this->processRow($id, $locked);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $this->releaseWithFailure($locked, $e);
            }
        }

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<string,mixed>|null Locked row snapshot.
     */
    private function claim(int $id): ?array
    {
        $leaseUntil = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $this->config->lockSeconds() . ' seconds')
            ->format('Y-m-d H:i:s.u');

        return $this->db->transaction(function () use ($id, $leaseUntil): ?array {
            $row = $this->outbox->lockById($id, 'skip_locked');
            if (!is_array($row)) {
                return null;
            }

            $status = (string)($row['status'] ?? '');
            if (!in_array($status, ['pending', 'failed'], true)) {
                return null;
            }

            if (!$this->isDue($row['next_attempt_at'] ?? null)) {
                return null;
            }

            $updated = $this->outbox->updateById($id, [
                'next_attempt_at' => $leaseUntil,
            ]);
            if ($updated <= 0) {
                return null;
            }

            $row['next_attempt_at'] = $leaseUntil;
            return $row;
        });
    }

    /**
     * @param array<string,mixed> $row Locked row snapshot.
     */
    private function processRow(int $id, array $row): void
    {
        $eventType = trim((string)($row['event_type'] ?? ''));
        if ($eventType === '') {
            throw new \RuntimeException('missing_event_type');
        }

        $payload = $this->decodeJson($row['payload'] ?? null);
        $payload = $this->maybeDecryptPayload('event_outbox', $payload);

        $headers = [
            'event_key' => $row['event_key'] ?? null,
            'entity_table' => $row['entity_table'] ?? null,
            'entity_pk' => $row['entity_pk'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];

        $headers = array_filter($headers, static fn($v) => $v !== null && $v !== '');

        $this->transport->publish(MessageEnvelope::wrap($eventType, $payload, $headers));

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->outbox->updateById($id, [
            'status' => 'sent',
            'processed_at' => $now,
            'next_attempt_at' => null,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function releaseWithFailure(array $row, \Throwable $e): void
    {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $attempts = (int)($row['attempts'] ?? 0);
        $attempts = max(0, $attempts + 1);

        $max = $this->config->maxAttempts();
        $message = substr($e->getMessage() ?: get_class($e), 0, 2000);

        if ($max > 0 && $attempts >= $max) {
            $this->logger->error('messaging.event_outbox.failed_permanent', [
                'id' => $id,
                'event_key' => $row['event_key'] ?? null,
                'event_type' => $row['event_type'] ?? null,
                'attempts' => $attempts,
                'error' => $message,
            ]);

            $this->outbox->updateById($id, [
                'status' => 'failed',
                'attempts' => $attempts,
                'next_attempt_at' => null,
            ]);
            return;
        }

        $delay = $this->retryDelaySeconds($attempts);
        $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $delay . ' seconds')
            ->format('Y-m-d H:i:s.u');

        $this->logger->warning('messaging.event_outbox.failed_retry', [
            'id' => $id,
            'event_key' => $row['event_key'] ?? null,
            'event_type' => $row['event_type'] ?? null,
            'attempts' => $attempts,
            'next_in_s' => $delay,
            'error' => $message,
        ]);

        $this->outbox->updateById($id, [
            'status' => 'failed',
            'attempts' => $attempts,
            'next_attempt_at' => $next,
        ]);
    }

    private function retryDelaySeconds(int $attempts): int
    {
        $attempts = max(0, $attempts);
        $base = max(1, $this->config->baseDelaySeconds());
        $max = max(1, $this->config->maxDelaySeconds());

        $exp = (int)min(10, $attempts);
        $delay = (int)min($max, $base * (1 << $exp));
        $jitter = random_int(0, 15);

        return min($max, max(1, $delay + $jitter));
    }

    private function isDue(mixed $nextAttemptAt): bool
    {
        if ($nextAttemptAt === null || $nextAttemptAt === '') {
            return true;
        }
        if ($nextAttemptAt instanceof \DateTimeInterface) {
            return $nextAttemptAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        if (!is_string($nextAttemptAt)) {
            return true;
        }

        try {
            $dt = new \DateTimeImmutable($nextAttemptAt, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return true;
        }
        return $dt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function maybeDecryptPayload(string $table, array $payload): array
    {
        try {
            $adapter = IngressLocator::adapter();
        } catch (\Throwable) {
            return $payload;
        }
        if (!is_object($adapter) || !method_exists($adapter, 'decrypt')) {
            return $payload;
        }

        try {
            /** @var array<string,mixed> $row */
            $row = $adapter->decrypt($table, [
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], [
                'strict' => false,
            ]);
            $decoded = $this->decodeJson($row['payload'] ?? null);
            return $decoded !== [] ? $decoded : $payload;
        } catch (\Throwable) {
            return $payload;
        }
    }
}

