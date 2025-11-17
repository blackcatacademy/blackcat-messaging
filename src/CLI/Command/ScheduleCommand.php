<?php
declare(strict_types=1);

namespace BlackCat\Messaging\CLI\Command;

use BlackCat\Messaging\Config\MessagingConfig;
use BlackCat\Messaging\MessagingManager;
use Psr\Log\LoggerInterface;

final class ScheduleCommand implements CommandInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function name(): string
    {
        return 'schedule';
    }

    public function description(): string
    {
        return 'Schedule a task run (delay queue / cron).';
    }

    public function run(array $args): int
    {
        $task = $args[0] ?? null;
        $runAt = $args[1] ?? '+1 minute';
        $payloadJson = $args[2] ?? '{}';
        if ($task === null) {
            throw new \InvalidArgumentException('Usage: messaging schedule <task> <run-at> [json]');
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Payload must be JSON');
        }
        $manager = MessagingManager::boot(MessagingConfig::fromEnv(), $this->logger);
        $manager->schedule($task, $runAt, $payload);
        return 0;
    }
}
