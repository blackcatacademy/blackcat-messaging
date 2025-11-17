<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Transport;

use BlackCat\Messaging\Contracts\TransportInterface;
use BlackCat\Messaging\Support\MessageEnvelope;

final class OutboxWebhookTransport implements TransportInterface
{
    public function publish(MessageEnvelope $envelope): void
    {
        // This transport is effectively a no-op here because outbox handles notifications itself.
    }

    public function subscribe(string $topic, callable $handler): void
    {
        throw new \RuntimeException('Webhook transport is write-only.');
    }
}
