<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Config;

use BlackCat\Messaging\Contracts\SchedulerInterface;
use BlackCat\Messaging\Contracts\TransportInterface;
use BlackCat\Messaging\Scheduler\InMemoryScheduler;
use BlackCat\Messaging\Scheduler\PostgresScheduler;
use BlackCat\Messaging\Transport\InMemoryTransport;
use BlackCat\Messaging\Transport\PostgresTransport;
use Closure;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class MessagingConfig
{
    public function __construct(
        private readonly array $transport = [],
        private readonly array $scheduler = [],
        private readonly string $storageDir = '',
        private readonly ?Closure $transportFactory = null,
        private readonly ?Closure $schedulerFactory = null,
    ) {
    }

    public static function fromEnv(array $env = []): self
    {
        $env = $env ?: $_ENV + $_SERVER;
        if (isset($env['BLACKCAT_MESSAGING_CONFIG_FILE'])) {
            return self::fromFile($env['BLACKCAT_MESSAGING_CONFIG_FILE']);
        }

        $inline = $env['BLACKCAT_MESSAGING_CONFIG'] ?? null;
        if (is_string($inline) && is_file($inline)) {
            return self::fromFile($inline);
        }

        $transport = json_decode($env['BLACKCAT_MESSAGING_TRANSPORT'] ?? '[]', true) ?: [];
        $scheduler = json_decode($env['BLACKCAT_MESSAGING_SCHEDULER'] ?? '[]', true) ?: [];
        $storage = $env['BLACKCAT_MESSAGING_STORAGE'] ?? (sys_get_temp_dir() . '/blackcat-messaging');

        return new self($transport, $scheduler, $storage);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Messaging config file not found: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $payload = match ($ext) {
            'php' => require $path,
            'json' => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
            'yml', 'yaml' => self::parseYaml($path),
            default => throw new InvalidArgumentException("Unsupported config format: {$ext}"),
        };

        if (!is_array($payload)) {
            throw new InvalidArgumentException("Messaging config must return array: {$path}");
        }

        $payload = self::resolvePlaceholders($payload);

        return new self(
            $payload['transport'] ?? [],
            $payload['scheduler'] ?? [],
            (string) ($payload['storage_dir'] ?? (sys_get_temp_dir() . '/blackcat-messaging'))
        );
    }

    public function transportDefinition(): array
    {
        return $this->transport;
    }

    public function schedulerDefinition(): array
    {
        return $this->scheduler;
    }

    public function storageDir(): string
    {
        return $this->storageDir ?: sys_get_temp_dir() . '/blackcat-messaging';
    }

    public function transportFactory(): Closure
    {
        if ($this->transportFactory) {
            return $this->transportFactory;
        }

        $definition = $this->transport;
        return function (MessagingConfig $config, ?LoggerInterface $logger = null) use ($definition): TransportInterface {
            $driver = $definition['driver'] ?? 'in-memory';
            return match ($driver) {
                'postgres' => new PostgresTransport($definition, $logger),
                default => new InMemoryTransport($logger),
            };
        };
    }

    public function schedulerFactory(): Closure
    {
        if ($this->schedulerFactory) {
            return $this->schedulerFactory;
        }

        $definition = $this->scheduler;
        return function (MessagingConfig $config, ?LoggerInterface $logger = null) use ($definition): SchedulerInterface {
            $driver = $definition['driver'] ?? 'in-memory';
            return match ($driver) {
                'postgres' => new PostgresScheduler($definition, $logger),
                default => new InMemoryScheduler($logger),
            };
        };
    }

    private static function parseYaml(string $path): array
    {
        if (!function_exists('yaml_parse_file')) {
            throw new InvalidArgumentException('ext-yaml required to parse messaging YAML config');
        }

        $parsed = yaml_parse_file($path);
        if (!is_array($parsed)) {
            throw new InvalidArgumentException("Invalid YAML config: {$path}");
        }

        return $parsed;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function resolvePlaceholders(mixed $value): mixed
    {
        if (is_string($value)) {
            if (preg_match('/^\$\{env:([^}]+)}/', $value, $m)) {
                return getenv($m[1]) ?: '';
            }

            if (preg_match('/^\$\{file:([^}]+)}/', $value, $m)) {
                $path = $m[1];
                return is_file($path) ? trim((string) file_get_contents($path)) : '';
            }

            return $value;
        }

        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $inner) {
                $resolved[$key] = self::resolvePlaceholders($inner);
            }

            return $resolved;
        }

        return $value;
    }
}
