<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Tests\Integration;

use BlackCat\Core\Database;
use BlackCat\Database\Installer;
use BlackCat\Database\Packages\EventOutbox\EventOutboxModule;
use BlackCat\Database\Packages\EventOutbox\Repository\EventOutboxRepository;
use BlackCat\Database\Packages\WebhookOutbox\Repository\WebhookOutboxRepository;
use BlackCat\Database\Packages\WebhookOutbox\WebhookOutboxModule;
use BlackCat\Database\Registry;
use BlackCat\Messaging\Config\EventOutboxWorkerConfig;
use BlackCat\Messaging\Config\WebhookOutboxWorkerConfig;
use BlackCat\Messaging\Contracts\WebhookDispatcherInterface;
use BlackCat\Messaging\Support\Uuid;
use BlackCat\Messaging\Support\WebhookDispatchResult;
use BlackCat\Messaging\Transport\InMemoryTransport;
use BlackCat\Messaging\Worker\EventOutboxWorker;
use BlackCat\Messaging\Worker\WebhookOutboxWorker;
use PHPUnit\Framework\TestCase;

final class OutboxWorkersIntegrationTest extends TestCase
{
    private static function initDb(): Database
    {
        $dsn = getenv('BC_TEST_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set BC_TEST_DSN to run DB integration test.');
        }

        $user = getenv('BC_TEST_DB_USER') ?: null;
        $pass = getenv('BC_TEST_DB_PASS') ?: null;

        if (!Database::isInitialized()) {
            Database::init([
                'dsn' => $dsn,
                'user' => $user,
                'pass' => $pass,
                'appName' => 'blackcat-messaging-tests',
                'requireSqlComment' => false,
            ]);
        }

        return Database::getInstance();
    }

    private static function installOutboxModules(Database $db): void
    {
        $installer = new Installer($db, $db->dialect());
        $registry = new Registry(
            new EventOutboxModule(),
            new WebhookOutboxModule(),
        );
        $registry->installOrUpgradeAll($installer);
    }

    public function testEventOutboxWorkerPublishesAndMarksSent(): void
    {
        $db = self::initDb();
        self::installOutboxModules($db);

        $repo = new EventOutboxRepository($db);
        $transport = new InMemoryTransport();

        $entityTable = 'test_outbox_' . substr(Uuid::v4(), 0, 8);
        $eventKey = Uuid::v4();

        $repo->insert([
            'event_key' => $eventKey,
            'entity_table' => $entityTable,
            'entity_pk' => '1',
            'event_type' => 'test.event',
            'payload' => json_encode(['hello' => 'world'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $cfg = new EventOutboxWorkerConfig(
            batchSize: 10,
            lockSeconds: 60,
            maxAttempts: 1,
            baseDelaySeconds: 5,
            maxDelaySeconds: 5,
            entityTable: $entityTable,
        );

        $worker = new EventOutboxWorker($db, $repo, $transport, $cfg);
        $report = $worker->runOnce();

        self::assertSame(1, $report['processed']);
        self::assertSame(1, $report['sent']);
        self::assertSame(0, $report['failed']);

        $messages = $transport->drain();
        self::assertCount(1, $messages);
        self::assertSame('test.event', $messages[0]->topic);
        self::assertSame(['hello' => 'world'], $messages[0]->payload);
        self::assertSame($eventKey, $messages[0]->headers['event_key']);
        self::assertSame($entityTable, $messages[0]->headers['entity_table']);
        self::assertSame('1', $messages[0]->headers['entity_pk']);

        $row = $db->fetch('SELECT status, processed_at FROM event_outbox WHERE event_key = :k', ['k' => $eventKey]);
        self::assertIsArray($row);
        self::assertSame('sent', $row['status']);
        self::assertNotEmpty($row['processed_at']);
    }

    public function testWebhookOutboxWorkerDispatchesAndMarksSent(): void
    {
        $db = self::initDb();
        self::installOutboxModules($db);

        $repo = new WebhookOutboxRepository($db);

        $eventType = 'test.webhook';
        $repo->insert([
            'event_type' => $eventType,
            'payload' => json_encode([
                'url' => 'https://example.test/webhook',
                'body' => ['ok' => true],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $called = 0;
        $dispatcher = new class($called) implements WebhookDispatcherInterface {
            public function __construct(private int &$called) {}
            public function dispatch(string $eventType, array $payload, array $meta = []): WebhookDispatchResult
            {
                unset($payload, $meta);
                $this->called++;
                return WebhookDispatchResult::ok(200);
            }
        };

        $cfg = new WebhookOutboxWorkerConfig(
            batchSize: 10,
            lockSeconds: 60,
            maxRetries: 1,
            baseDelaySeconds: 5,
            maxDelaySeconds: 5,
            httpTimeoutSeconds: 1,
        );

        $worker = new WebhookOutboxWorker($db, $repo, $cfg, $dispatcher);
        $report = $worker->runOnce();

        self::assertSame(1, $report['processed']);
        self::assertSame(1, $report['sent']);
        self::assertSame(0, $report['failed']);
        self::assertSame(1, $called);

        $row = $db->fetch(
            'SELECT status FROM webhook_outbox WHERE event_type = :t ORDER BY id DESC LIMIT 1',
            ['t' => $eventType]
        );
        self::assertIsArray($row);
        self::assertSame('sent', $row['status']);
    }
}

