<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Support;

final class WebhookDispatchResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function ok(?int $httpStatus = null): self
    {
        return new self(true, $httpStatus, null);
    }

    public static function failed(string $error, ?int $httpStatus = null): self
    {
        $error = trim($error);
        return new self(false, $httpStatus, $error !== '' ? $error : 'webhook_failed');
    }
}

