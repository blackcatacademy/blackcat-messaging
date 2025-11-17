<?php
declare(strict_types=1);

namespace BlackCat\Messaging\CLI\Command;

use BlackCat\Messaging\Config\MessagingConfig;
use BlackCat\Messaging\Support\LocalEventStore;
use Psr\Log\LoggerInterface;

final class TailCommand implements CommandInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function name(): string
    {
        return 'tail';
    }

    public function description(): string
    {
        return 'Tail recent events from the local storage (dev mode).';
    }

    public function run(array $args): int
    {
        $config = MessagingConfig::fromEnv();
        $store = new LocalEventStore($config->storageDir());
        $events = array_slice($store->events(), -10);
        echo json_encode($events, JSON_PRETTY_PRINT) . PHP_EOL;
        return 0;
    }
}
