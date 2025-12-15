<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Worker;

use BlackCat\Core\Database;
use BlackCat\Database\Crypto\IngressLocator;
use BlackCat\Database\Packages\WebhookOutbox\Repository\WebhookOutboxRepository;
use BlackCat\Messaging\Config\WebhookOutboxWorkerConfig;
use BlackCat\Messaging\Contracts\WebhookDispatcherInterface;
use BlackCat\Messaging\Webhook\HttpWebhookDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class WebhookOutboxWorker
{
    private readonly LoggerInterface $logger;
    private readonly WebhookDispatcherInterface $dispatcher;

    public function __construct(
        private readonly Database $db,
        private readonly WebhookOutboxRepository $outbox,
        private readonly WebhookOutboxWorkerConfig $config,
        ?WebhookDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->dispatcher = $dispatcher ?? new HttpWebhookDispatcher($config->httpTimeoutSeconds());
    }

    /**
     * @return array{processed:int,sent:int,failed:int,skipped:int}
     */
    public function runOnce(): array
    {
        $limit = $this->config->batchSize();

        $ids = $this->db->fetchAll(
            "SELECT id FROM vw_webhook_outbox_due ORDER BY id ASC LIMIT :lim",
            ['lim' => $limit]
        );

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
        $payload = $this->maybeDecryptPayload('webhook_outbox', $payload);

        $meta = [
            'id' => $id,
            'event_type' => $eventType,
            'retries' => (int)($row['retries'] ?? 0),
        ];

        $result = $this->dispatcher->dispatch($eventType, $payload, $meta);
        if (!$result->ok) {
            $err = $result->error ?? 'webhook_failed';
            throw new \RuntimeException($err);
        }

        $this->outbox->updateById($id, [
            'status' => 'sent',
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

        $retries = (int)($row['retries'] ?? 0);
        $retries = max(0, $retries + 1);

        $max = $this->config->maxRetries();
        $message = substr($e->getMessage() ?: get_class($e), 0, 2000);

        if ($max > 0 && $retries >= $max) {
            $this->logger->error('messaging.webhook_outbox.failed_permanent', [
                'id' => $id,
                'event_type' => $row['event_type'] ?? null,
                'retries' => $retries,
                'error' => $message,
            ]);

            $this->outbox->updateById($id, [
                'status' => 'failed',
                'retries' => $retries,
                'next_attempt_at' => null,
            ]);
            return;
        }

        $delay = $this->retryDelaySeconds($retries);
        $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $delay . ' seconds')
            ->format('Y-m-d H:i:s.u');

        $this->logger->warning('messaging.webhook_outbox.failed_retry', [
            'id' => $id,
            'event_type' => $row['event_type'] ?? null,
            'retries' => $retries,
            'next_in_s' => $delay,
            'error' => $message,
        ]);

        $this->outbox->updateById($id, [
            'status' => 'failed',
            'retries' => $retries,
            'next_attempt_at' => $next,
        ]);
    }

    private function retryDelaySeconds(int $retries): int
    {
        $retries = max(0, $retries);
        $base = max(1, $this->config->baseDelaySeconds());
        $max = max(1, $this->config->maxDelaySeconds());

        $exp = (int)min(10, $retries);
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

