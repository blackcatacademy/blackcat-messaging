<?php
declare(strict_types=1);

namespace BlackCat\Messaging\CLI\Command;

use BlackCat\Messaging\Config\MessagingConfig;
use BlackCat\Messaging\MessagingManager;
use Psr\Log\LoggerInterface;

final class PublishCommand implements CommandInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function name(): string
    {
        return 'publish';
    }

    public function description(): string
    {
        return 'Publish a JSON payload to a topic.';
    }

    public function run(array $args): int
    {
        [$topic, $payload] = $this->parse($args);
        $manager = MessagingManager::boot(MessagingConfig::fromEnv(), $this->logger);
        $manager->publish($topic, $payload);
        return 0;
    }

    private function parse(array $args): array
    {
        $topic = $args[0] ?? null;
        $payload = $args[1] ?? '{}';
        if ($topic === null) {
            throw new \InvalidArgumentException('Usage: messaging publish <topic> [json-payload]');
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Payload must be valid JSON object');
        }
        return [$topic, $decoded];
    }
}
