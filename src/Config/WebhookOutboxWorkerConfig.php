<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Config;

final class WebhookOutboxWorkerConfig
{
    public function __construct(
        private readonly int $batchSize = 100,
        private readonly int $lockSeconds = 300,
        private readonly int $maxRetries = 0,
        private readonly int $baseDelaySeconds = 10,
        private readonly int $maxDelaySeconds = 3600,
        private readonly int $httpTimeoutSeconds = 5,
        private readonly string $workerName = 'blackcat-webhook-outbox-worker',
    ) {
    }

    /**
     * @param array<string,string|int|bool|null> $env
     */
    public static function fromEnv(array $env = []): self
    {
        $env = $env ?: ($_ENV + $_SERVER);

        $batch = (int)($env['BLACKCAT_WEBHOOK_OUTBOX_BATCH_SIZE'] ?? 100);
        $lock = (int)($env['BLACKCAT_WEBHOOK_OUTBOX_LOCK_SECONDS'] ?? 300);
        $maxRetries = (int)($env['BLACKCAT_WEBHOOK_OUTBOX_MAX_RETRIES'] ?? 0);
        $baseDelay = (int)($env['BLACKCAT_WEBHOOK_OUTBOX_BASE_DELAY_SECONDS'] ?? 10);
        $maxDelay = (int)($env['BLACKCAT_WEBHOOK_OUTBOX_MAX_DELAY_SECONDS'] ?? 3600);
        $timeout = (int)($env['BLACKCAT_WEBHOOK_OUTBOX_HTTP_TIMEOUT_SECONDS'] ?? 5);

        $workerName = trim((string)($env['BLACKCAT_WEBHOOK_OUTBOX_WORKER_NAME'] ?? 'blackcat-webhook-outbox-worker'));

        return new self(
            batchSize: max(1, $batch),
            lockSeconds: max(5, $lock),
            maxRetries: max(0, $maxRetries),
            baseDelaySeconds: max(1, $baseDelay),
            maxDelaySeconds: max(1, $maxDelay),
            httpTimeoutSeconds: max(1, $timeout),
            workerName: $workerName !== '' ? $workerName : 'blackcat-webhook-outbox-worker',
        );
    }

    public function batchSize(): int { return $this->batchSize; }
    public function lockSeconds(): int { return $this->lockSeconds; }
    public function maxRetries(): int { return $this->maxRetries; }
    public function baseDelaySeconds(): int { return $this->baseDelaySeconds; }
    public function maxDelaySeconds(): int { return $this->maxDelaySeconds; }
    public function httpTimeoutSeconds(): int { return $this->httpTimeoutSeconds; }
    public function workerName(): string { return $this->workerName; }
}

