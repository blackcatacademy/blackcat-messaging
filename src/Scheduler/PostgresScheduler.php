<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Scheduler;

use BlackCat\Messaging\Contracts\SchedulerInterface;
use BlackCat\Messaging\Support\MessageEnvelope;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PostgresScheduler implements SchedulerInterface
{
    private PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(private readonly array $config, ?LoggerInterface $logger = null)
    {
        $dsn = $config['dsn'] ?? null;
        if ($dsn === null) {
            throw new \InvalidArgumentException('Postgres scheduler requires dsn');
        }

        $user = $config['user'] ?? null;
        $password = $config['password'] ?? null;

        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->logger = $logger ?? new NullLogger();
        $this->ensureTable();
    }

    public function schedule(MessageEnvelope $message, string $runAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messaging_jobs (id, task, payload, headers, run_at, status)
             VALUES (:id, :task, :payload::jsonb, :headers::jsonb, :run_at, \'pending\')'
        );

        $id = bin2hex(random_bytes(16));
        $stmt->execute([
            ':id' => $id,
            ':task' => $message->topic,
            ':payload' => json_encode($message->payload, JSON_THROW_ON_ERROR),
            ':headers' => json_encode($message->headers, JSON_THROW_ON_ERROR),
            ':run_at' => $runAt,
        ]);

        $this->logger->info('messaging.pg.schedule', ['task' => $message->topic, 'runAt' => $runAt, 'id' => $id]);
    }

    public function due(string $now = 'now'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, task, payload, headers, run_at FROM messaging_jobs
             WHERE status = \'pending\' AND run_at <= :now ORDER BY run_at ASC LIMIT 100'
        );
        $stmt->execute([':now' => $now]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS messaging_jobs (
                id TEXT PRIMARY KEY,
                task TEXT NOT NULL,
                payload JSONB NOT NULL,
                headers JSONB NOT NULL,
                run_at TIMESTAMPTZ NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }
}
