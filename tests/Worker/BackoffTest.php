<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Tests\Worker;

use BlackCat\Messaging\Config\EventOutboxWorkerConfig;
use BlackCat\Messaging\Config\WebhookOutboxWorkerConfig;
use BlackCat\Messaging\Worker\EventOutboxWorker;
use BlackCat\Messaging\Worker\WebhookOutboxWorker;
use PHPUnit\Framework\TestCase;

final class BackoffTest extends TestCase
{
    public function testEventOutboxBackoffIsCappedWhenMaxEqualsBase(): void
    {
        $cfg = new EventOutboxWorkerConfig(
            batchSize: 1,
            lockSeconds: 10,
            maxAttempts: 1,
            baseDelaySeconds: 7,
            maxDelaySeconds: 7,
            entityTable: '',
        );

        $ref = new \ReflectionClass(EventOutboxWorker::class);
        $worker = $ref->newInstanceWithoutConstructor();
        $p = $ref->getProperty('config');
        $p->setAccessible(true);
        $p->setValue($worker, $cfg);

        $m = $ref->getMethod('retryDelaySeconds');
        $m->setAccessible(true);

        self::assertSame(7, $m->invoke($worker, 0));
        self::assertSame(7, $m->invoke($worker, 1));
        self::assertSame(7, $m->invoke($worker, 5));
    }

    public function testWebhookOutboxBackoffIsCappedWhenMaxEqualsBase(): void
    {
        $cfg = new WebhookOutboxWorkerConfig(
            batchSize: 1,
            lockSeconds: 10,
            maxRetries: 1,
            baseDelaySeconds: 9,
            maxDelaySeconds: 9,
            httpTimeoutSeconds: 1,
        );

        $ref = new \ReflectionClass(WebhookOutboxWorker::class);
        $worker = $ref->newInstanceWithoutConstructor();
        $p = $ref->getProperty('config');
        $p->setAccessible(true);
        $p->setValue($worker, $cfg);

        $m = $ref->getMethod('retryDelaySeconds');
        $m->setAccessible(true);

        self::assertSame(9, $m->invoke($worker, 0));
        self::assertSame(9, $m->invoke($worker, 1));
        self::assertSame(9, $m->invoke($worker, 5));
    }
}

