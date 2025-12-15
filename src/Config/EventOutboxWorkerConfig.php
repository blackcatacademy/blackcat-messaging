<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Config;

final class EventOutboxWorkerConfig
{
    public function __construct(
        private readonly int $batchSize = 100,
        private readonly int $lockSeconds = 300,
        private readonly int $maxAttempts = 0,
        private readonly int $baseDelaySeconds = 10,
        private readonly int $maxDelaySeconds = 3600,
        private readonly string $entityTable = '',
        private readonly string $workerName = 'blackcat-event-outbox-worker',
    ) {
    }

    /**
     * @param array<string,string|int|bool|null> $env
     */
    public static function fromEnv(array $env = []): self
    {
        $env = $env ?: ($_ENV + $_SERVER);

        $batch = (int)($env['BLACKCAT_EVENT_OUTBOX_BATCH_SIZE'] ?? 100);
        $lock = (int)($env['BLACKCAT_EVENT_OUTBOX_LOCK_SECONDS'] ?? 300);
        $maxAttempts = (int)($env['BLACKCAT_EVENT_OUTBOX_MAX_ATTEMPTS'] ?? 0);
        $baseDelay = (int)($env['BLACKCAT_EVENT_OUTBOX_BASE_DELAY_SECONDS'] ?? 10);
        $maxDelay = (int)($env['BLACKCAT_EVENT_OUTBOX_MAX_DELAY_SECONDS'] ?? 3600);

        $entityTable = trim((string)($env['BLACKCAT_EVENT_OUTBOX_ENTITY_TABLE'] ?? ''));
        $workerName = trim((string)($env['BLACKCAT_EVENT_OUTBOX_WORKER_NAME'] ?? 'blackcat-event-outbox-worker'));

        return new self(
            batchSize: max(1, $batch),
            lockSeconds: max(5, $lock),
            maxAttempts: max(0, $maxAttempts),
            baseDelaySeconds: max(1, $baseDelay),
            maxDelaySeconds: max(1, $maxDelay),
            entityTable: $entityTable,
            workerName: $workerName !== '' ? $workerName : 'blackcat-event-outbox-worker',
        );
    }

    public function batchSize(): int { return $this->batchSize; }
    public function lockSeconds(): int { return $this->lockSeconds; }
    public function maxAttempts(): int { return $this->maxAttempts; }
    public function baseDelaySeconds(): int { return $this->baseDelaySeconds; }
    public function maxDelaySeconds(): int { return $this->maxDelaySeconds; }
    public function entityTable(): string { return $this->entityTable; }
    public function workerName(): string { return $this->workerName; }
}

