<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Tests\Config;

use BlackCat\Messaging\Config\WebhookOutboxWorkerConfig;
use PHPUnit\Framework\TestCase;

final class WebhookOutboxWorkerConfigTest extends TestCase
{
    public function testFromEnvClampsAndTrims(): void
    {
        $cfg = WebhookOutboxWorkerConfig::fromEnv([
            'BLACKCAT_WEBHOOK_OUTBOX_BATCH_SIZE' => '0',
            'BLACKCAT_WEBHOOK_OUTBOX_LOCK_SECONDS' => '1',
            'BLACKCAT_WEBHOOK_OUTBOX_MAX_RETRIES' => '-2',
            'BLACKCAT_WEBHOOK_OUTBOX_BASE_DELAY_SECONDS' => '0',
            'BLACKCAT_WEBHOOK_OUTBOX_MAX_DELAY_SECONDS' => '0',
            'BLACKCAT_WEBHOOK_OUTBOX_HTTP_TIMEOUT_SECONDS' => '0',
            'BLACKCAT_WEBHOOK_OUTBOX_WORKER_NAME' => '',
        ]);

        self::assertSame(1, $cfg->batchSize());
        self::assertSame(5, $cfg->lockSeconds());
        self::assertSame(0, $cfg->maxRetries());
        self::assertSame(1, $cfg->baseDelaySeconds());
        self::assertSame(1, $cfg->maxDelaySeconds());
        self::assertSame(1, $cfg->httpTimeoutSeconds());
        self::assertSame('blackcat-webhook-outbox-worker', $cfg->workerName());
    }
}

