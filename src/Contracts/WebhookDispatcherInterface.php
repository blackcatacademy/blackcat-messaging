<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Contracts;

use BlackCat\Messaging\Support\WebhookDispatchResult;

interface WebhookDispatcherInterface
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $meta
     */
    public function dispatch(string $eventType, array $payload, array $meta = []): WebhookDispatchResult;
}

