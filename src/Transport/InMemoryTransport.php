<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Transport;

use BlackCat\Messaging\Contracts\TransportInterface;
use BlackCat\Messaging\Support\MessageEnvelope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class InMemoryTransport implements TransportInterface
{
    /** @var list<MessageEnvelope> */
    private array $buffer = [];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function publish(MessageEnvelope $message): void
    {
        $this->buffer[] = $message;
        ($this->logger ?? new NullLogger())->debug('messaging.in-memory.publish', [
            'topic' => $message->topic,
            'headers' => $message->headers,
        ]);
    }

    /**
     * @return list<MessageEnvelope>
     */
    public function drain(): array
    {
        $buffer = $this->buffer;
        $this->buffer = [];
        return $buffer;
    }
}
