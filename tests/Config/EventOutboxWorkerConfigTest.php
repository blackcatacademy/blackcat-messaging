<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Tests\Config;

use BlackCat\Messaging\Config\EventOutboxWorkerConfig;
use PHPUnit\Framework\TestCase;

final class EventOutboxWorkerConfigTest extends TestCase
{
    public function testFromEnvClampsAndTrims(): void
    {
        $cfg = EventOutboxWorkerConfig::fromEnv([
            'BLACKCAT_EVENT_OUTBOX_BATCH_SIZE' => '0',
            'BLACKCAT_EVENT_OUTBOX_LOCK_SECONDS' => '1',
            'BLACKCAT_EVENT_OUTBOX_MAX_ATTEMPTS' => '-5',
            'BLACKCAT_EVENT_OUTBOX_BASE_DELAY_SECONDS' => '0',
            'BLACKCAT_EVENT_OUTBOX_MAX_DELAY_SECONDS' => '0',
            'BLACKCAT_EVENT_OUTBOX_ENTITY_TABLE' => '  outbox  ',
            'BLACKCAT_EVENT_OUTBOX_WORKER_NAME' => '',
        ]);

        self::assertSame(1, $cfg->batchSize());
        self::assertSame(5, $cfg->lockSeconds());
        self::assertSame(0, $cfg->maxAttempts());
        self::assertSame(1, $cfg->baseDelaySeconds());
        self::assertSame(1, $cfg->maxDelaySeconds());
        self::assertSame('outbox', $cfg->entityTable());
        self::assertSame('blackcat-event-outbox-worker', $cfg->workerName());
    }
}

