<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Support;

final class MessageEnvelope
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $headers
     */
    private function __construct(
        public readonly string $topic,
        public readonly array $payload,
        public readonly array $headers = [],
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $headers
     */
    public static function wrap(string $topic, array $payload, array $headers = []): self
    {
        $headers['x-created-at'] = $headers['x-created-at'] ?? gmdate(DATE_ATOM);
        return new self($topic, $payload, $headers);
    }

    public function toArray(): array
    {
        return [
            'topic' => $this->topic,
            'payload' => $this->payload,
            'headers' => $this->headers,
        ];
    }
}
