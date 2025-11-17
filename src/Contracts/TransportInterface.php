<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Contracts;

use BlackCat\Messaging\Support\MessageEnvelope;

interface TransportInterface
{
    public function publish(MessageEnvelope $message): void;
}
