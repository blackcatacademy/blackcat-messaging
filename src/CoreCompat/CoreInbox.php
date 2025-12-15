<?php
declare(strict_types=1);

namespace BlackCat\Messaging\CoreCompat;

use BlackCat\Core\Database;
use BlackCat\Database\Packages\EventInbox\Definitions as EventInboxDefinitions;
use BlackCat\Database\Packages\EventInbox\Repository\EventInboxRepository;
use BlackCat\Messaging\Support\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Backwards-compat for `BlackCat\Core\Messaging\Inbox`.
 *
 * Uses `event_inbox` (unique by source+event_key). The legacy `$table` maps to `source`.
 */
final class CoreInbox
{
    private readonly EventInboxRepository $repo;

    public function __construct(
        private readonly Database $db,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $table = 'inbox'
    ) {
        $this->repo = new EventInboxRepository($db);
    }

    /**
     * Processes a message exactly once.
     *
     * @param callable $handler Invoked inside the same logical unit; throw on failure.
     */
    public function process(string $messageId, string $topic, callable $handler): bool
    {
        $source = $this->normalizeFixedString($this->table, 100, 'inbox');
        $eventKey = Uuid::normalize($messageId, $source);

        $meta = ['component' => 'inbox', 'message_id' => $messageId, 'topic' => $topic, 'source' => $source];

        $thrown = null;
        $result = (bool)$this->db->txWithMeta(
            function () use ($source, $eventKey, $topic, $handler, &$thrown, $meta): bool {
                $now = $this->utcNowSql();
                $payloadJson = json_encode(['topic' => $topic], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($payloadJson === false) {
                    $payloadJson = '{}';
                }

                $row = null;
                try {
                    $this->repo->insert([
                        'source' => $source,
                        'event_key' => $eventKey,
                        'payload' => $payloadJson,
                    ]);
                    $row = $this->repo->getBySourceAndEventKey($source, $eventKey, false);
                } catch (\Throwable $e) {
                    if (!$this->isDuplicateError($e)) {
                        throw $e;
                    }
                    $row = $this->repo->getBySourceAndEventKey($source, $eventKey, false);
                }

                if (!is_array($row) || !isset($row['id'])) {
                    $this->logger?->info('inbox-duplicate', $meta);
                    return false;
                }

                $id = (int)$row['id'];
                if ($id <= 0) {
                    $this->logger?->info('inbox-duplicate', $meta);
                    return false;
                }

                $locked = $this->repo->lockById($id, 'wait', 'update');
                if (!is_array($locked)) {
                    $this->logger?->info('inbox-locked', $meta);
                    return false;
                }

                if (($locked['status'] ?? null) === 'processed') {
                    $this->logger?->info('inbox-duplicate', $meta);
                    return false;
                }

                try {
                    $handler();
                    $this->repo->updateById($id, [
                        'status' => 'processed',
                        'processed_at' => $now,
                        'last_error' => null,
                    ]);
                    return true;
                } catch (\Throwable $e) {
                    $err = substr((string)($e->getMessage() ?? ''), 0, 2000);
                    $attempts = (int)($locked['attempts'] ?? 0);
                    $this->repo->updateById($id, [
                        'status' => 'failed',
                        'attempts' => $attempts + 1,
                        'last_error' => $err,
                    ]);
                    $this->logger?->error('inbox-handler-failed', ['error' => $err] + $meta);
                    $thrown = $e;
                    return false;
                }
            },
            $meta,
            ['readOnly' => false]
        );

        if ($thrown instanceof \Throwable) {
            throw $thrown;
        }

        return $result;
    }

    public function ack(string $messageId): void
    {
        $source = $this->normalizeFixedString($this->table, 100, 'inbox');
        $eventKey = Uuid::normalize($messageId, $source);

        $row = $this->repo->getBySourceAndEventKey($source, $eventKey, false);
        if (!is_array($row) || !isset($row['id'])) {
            return;
        }

        $id = (int)$row['id'];
        if ($id <= 0) {
            return;
        }

        $this->repo->updateById($id, [
            'status' => 'processed',
            'processed_at' => $this->utcNowSql(),
            'last_error' => null,
        ]);
    }

    public function cleanup(string $status = 'processed', int $olderThanDays = 30): int
    {
        $olderThanDays = max(0, $olderThanDays);
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . $olderThanDays . ' days')
            ->format('Y-m-d H:i:s.u');

        $source = $this->normalizeFixedString($this->table, 100, 'inbox');
        $tbl = $this->db->quoteIdent(EventInboxDefinitions::table());

        return (int)$this->db->execute(
            "DELETE FROM {$tbl} WHERE source = :src AND status = 'processed' AND processed_at IS NOT NULL AND processed_at < :cutoff",
            [':src' => $source, ':cutoff' => $cutoff]
        );
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

