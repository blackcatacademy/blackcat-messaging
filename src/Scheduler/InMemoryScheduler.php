<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Scheduler;

use BlackCat\Messaging\Contracts\SchedulerInterface;
use BlackCat\Messaging\Support\MessageEnvelope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class InMemoryScheduler implements SchedulerInterface
{
    /** @var list<array{runAt:string,message:MessageEnvelope}> */
    private array $queue = [];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function schedule(MessageEnvelope $message, string $runAt): void
    {
        $this->queue[] = ['runAt' => $runAt, 'message' => $message];
        ($this->logger ?? new NullLogger())->debug('messaging.in-memory.schedule', [
            'task' => $message->topic,
            'runAt' => $runAt,
        ]);
    }

    /**
     * @return list<array{runAt:string,message:MessageEnvelope}>
     */
    public function due(string $now = 'now'): array
    {
        return $this->queue;
    }
}
