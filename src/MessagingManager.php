<?php
declare(strict_types=1);

namespace BlackCat\Messaging;

use BlackCat\Messaging\Config\MessagingConfig;
use BlackCat\Messaging\Contracts\TransportInterface;
use BlackCat\Messaging\Contracts\SchedulerInterface;
use BlackCat\Messaging\Support\LocalEventStore;
use BlackCat\Messaging\Support\MessageEnvelope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Central facade covering publish/subscribe, job queue and scheduling.
 * Actual implementations live in transports; this manager enforces global standards
 * (envelope format, crypto/HMAC integration, tracing metadata).
 */
final class MessagingManager
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly SchedulerInterface $scheduler,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?LocalEventStore $eventStore = null,
    ) {}

    public static function boot(MessagingConfig $config, ?LoggerInterface $logger = null): self
    {
        $logger ??= new NullLogger();
        $transport = $config->transportFactory()($config, $logger);
        $scheduler = $config->schedulerFactory()($config, $logger);
        $store = new LocalEventStore($config->storageDir());
        return new self($transport, $scheduler, $logger, $store);
    }

    /**
     * Publish an event/command into the bus.
     *
     * @param array<string,mixed> $payload
     */
    public function publish(string $topic, array $payload, array $headers = []): void
    {
        $envelope = MessageEnvelope::wrap($topic, $payload, $headers);
        $this->transport->publish($envelope);
        $this->eventStore?->append([
            'type' => 'publish',
            'topic' => $topic,
            'payload' => $payload,
            'headers' => $headers,
        ]);
    }

    /**
     * Schedule a task for later execution (cron/delay queue).
     *
     * @param array<string,mixed> $payload
     */
    public function schedule(string $task, string $runAt, array $payload = []): void
    {
        $envelope = MessageEnvelope::wrap($task, $payload, ['scheduled_at' => $runAt]);
        $this->scheduler->schedule($envelope, $runAt);
        $this->eventStore?->append([
            'type' => 'schedule',
            'task' => $task,
            'run_at' => $runAt,
            'payload' => $payload,
        ]);
    }
}
