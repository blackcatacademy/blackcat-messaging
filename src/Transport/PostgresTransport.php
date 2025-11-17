<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Transport;

use BlackCat\Messaging\Contracts\TransportInterface;
use BlackCat\Messaging\Support\MessageEnvelope;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PostgresTransport implements TransportInterface
{
    private PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(private readonly array $config, ?LoggerInterface $logger = null)
    {
        $dsn = $config['dsn'] ?? null;
        if ($dsn === null) {
            throw new \InvalidArgumentException('Postgres transport requires dsn');
        }

        $user = $config['user'] ?? null;
        $password = $config['password'] ?? null;

        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->logger = $logger ?? new NullLogger();
        $this->ensureTable();
    }

    public function publish(MessageEnvelope $message): void
    {
        $id = bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            'INSERT INTO messaging_messages (id, topic, payload, headers, created_at)
             VALUES (:id, :topic, :payload::jsonb, :headers::jsonb, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':id' => $id,
            ':topic' => $message->topic,
            ':payload' => json_encode($message->payload, JSON_THROW_ON_ERROR),
            ':headers' => json_encode($message->headers, JSON_THROW_ON_ERROR),
        ]);

        $notifyChannel = $this->config['channel'] ?? 'messaging_messages';
        try {
            $payload = json_encode(['id' => $id, 'topic' => $message->topic], JSON_THROW_ON_ERROR);
            $this->pdo->exec("NOTIFY {$notifyChannel}, " . $this->pdo->quote($payload));
        } catch (PDOException) {
            // LISTEN/NOTIFY optional
        }

        $this->logger->info('messaging.pg.publish', ['topic' => $message->topic, 'id' => $id]);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS messaging_messages (
                id TEXT PRIMARY KEY,
                topic TEXT NOT NULL,
                payload JSONB NOT NULL,
                headers JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }
}
