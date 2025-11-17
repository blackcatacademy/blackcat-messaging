<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Support;

final class LocalEventStore
{
    private string $file;

    public function __construct(string $storageDir)
    {
        if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new \RuntimeException("Unable to create storage dir {$storageDir}");
        }
        $this->file = rtrim($storageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'events.ndjson';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function append(array $payload): void
    {
        $payload['timestamp'] = time();
        file_put_contents($this->file, json_encode($payload) . PHP_EOL, FILE_APPEND);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function events(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        $events = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        return $events;
    }
}
