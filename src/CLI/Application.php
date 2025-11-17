<?php
declare(strict_types=1);

namespace BlackCat\Messaging\CLI;

use BlackCat\Messaging\CLI\Command\CommandInterface;
use BlackCat\Messaging\CLI\Command\PublishCommand;
use BlackCat\Messaging\CLI\Command\TailCommand;
use BlackCat\Messaging\CLI\Command\ScheduleCommand;
use Psr\Log\NullLogger;

final class Application
{
    /** @var array<string,CommandInterface> */
    private array $commands = [];

    public function __construct()
    {
        $logger = new NullLogger();
        $this->register(new PublishCommand($logger));
        $this->register(new TailCommand($logger));
        $this->register(new ScheduleCommand($logger));
    }

    public function register(CommandInterface $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'help';
        if ($name === 'help' || !isset($this->commands[$name])) {
            $this->printHelp();
            return $name === 'help' ? 0 : 1;
        }
        return $this->commands[$name]->run(array_slice($argv, 2));
    }

    private function printHelp(): void
    {
        echo "Available commands:\n";
        foreach ($this->commands as $command) {
            echo sprintf("  %s - %s\n", $command->name(), $command->description());
        }
    }
}
