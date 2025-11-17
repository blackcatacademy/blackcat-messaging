<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Contracts;

use BlackCat\Messaging\Support\MessageEnvelope;

interface SchedulerInterface
{
    public function schedule(MessageEnvelope $message, string $runAt): void;
}
