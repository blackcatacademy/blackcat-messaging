<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Tests\Webhook;

use BlackCat\Messaging\Webhook\HttpWebhookDispatcher;
use PHPUnit\Framework\TestCase;

final class HttpWebhookDispatcherTest extends TestCase
{
    public function testDispatchFailsWhenUrlMissing(): void
    {
        $dispatcher = new HttpWebhookDispatcher();
        $result = $dispatcher->dispatch('test', ['body' => ['ok' => true]]);

        self::assertFalse($result->ok);
        self::assertSame('missing_webhook_url', $result->error);
    }

    public function testReadUrlSupportsAliases(): void
    {
        $dispatcher = new HttpWebhookDispatcher();

        $ref = new \ReflectionClass($dispatcher);
        $m = $ref->getMethod('readUrl');
        $m->setAccessible(true);

        self::assertSame('https://example.test', $m->invoke($dispatcher, ['url' => ' https://example.test ']));
        self::assertSame('https://example.test', $m->invoke($dispatcher, ['webhook_url' => 'https://example.test']));
        self::assertSame('https://example.test', $m->invoke($dispatcher, ['endpoint' => 'https://example.test']));
        self::assertNull($m->invoke($dispatcher, ['url' => '']));
        self::assertNull($m->invoke($dispatcher, ['url' => ['x']]));
    }

    public function testResolveBodyPrefersBodyThenPayload(): void
    {
        $dispatcher = new HttpWebhookDispatcher();

        $ref = new \ReflectionClass($dispatcher);
        $m = $ref->getMethod('resolveBody');
        $m->setAccessible(true);

        self::assertSame(['a' => 1], $m->invoke($dispatcher, ['body' => ['a' => 1], 'payload' => ['b' => 2]]));
        self::assertSame(['b' => 2], $m->invoke($dispatcher, ['payload' => ['b' => 2]]));

        $resolved = $m->invoke($dispatcher, [
            'url' => 'https://example.test',
            'method' => 'POST',
            'headers' => ['X' => '1'],
            'foo' => 'bar',
        ]);
        self::assertSame(['foo' => 'bar'], $resolved);
    }

    public function testNormalizeHeadersAcceptsAssocOrList(): void
    {
        $dispatcher = new HttpWebhookDispatcher();

        $ref = new \ReflectionClass($dispatcher);
        $m = $ref->getMethod('normalizeHeaders');
        $m->setAccessible(true);

        self::assertSame(['X-Foo: bar'], $m->invoke($dispatcher, ['X-Foo' => 'bar']));
        self::assertSame(['X-Foo: bar'], $m->invoke($dispatcher, [' X-Foo ' => ' bar ']));
        self::assertSame(['X-Foo: bar'], $m->invoke($dispatcher, ['X-Foo: bar', '']));
    }
}

