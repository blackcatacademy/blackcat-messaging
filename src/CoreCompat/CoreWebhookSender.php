<?php
declare(strict_types=1);

namespace BlackCat\Messaging\CoreCompat;

use BlackCat\Core\Messaging\Sender\OutboxSender;
use BlackCat\Messaging\Contracts\WebhookDispatcherInterface;
use BlackCat\Messaging\Webhook\HttpWebhookDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Backwards-compat sender for legacy `BlackCat\Core\Messaging\Sender\WebhookSender`.
 *
 * This keeps HTTP/webhook concerns inside `blackcat-messaging` so `blackcat-core` stays kernel-only.
 */
final class CoreWebhookSender implements OutboxSender
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?WebhookDispatcherInterface $dispatcher = null,
        private readonly int $timeoutSeconds = 5,
        private readonly string $userAgent = 'blackcat-core-webhook-sender/1.0',
    ) {
    }

    public function send(array $row): bool
    {
        $payload = $this->decodeJsonArray($row['payload'] ?? null);
        $headers = $this->decodeJsonArray($row['headers'] ?? null);

        $url = $headers['webhook_url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            $this->logger?->warning('webhook-sender-missing-url', ['id' => $row['id'] ?? null]);
            return true;
        }

        $eventType = $row['topic'] ?? null;
        $eventType = is_string($eventType) ? trim($eventType) : '';
        if ($eventType === '') {
            $eventType = 'event';
        }

        $httpHeaders = $headers;
        unset($httpHeaders['webhook_url']);

        $requestPayload = [
            'webhook_url' => $url,
            'payload' => $payload,
        ];
        if ($httpHeaders !== []) {
            $requestPayload['headers'] = $httpHeaders;
        }

        $dispatcher = $this->dispatcher;
        if ($dispatcher === null) {
            if (!function_exists('curl_init')) {
                $this->logger?->error('webhook-sender-missing-curl', ['id' => $row['id'] ?? null]);
                return false;
            }
            $dispatcher = new HttpWebhookDispatcher($this->timeoutSeconds, $this->userAgent);
        }

        $result = $dispatcher->dispatch($eventType, $requestPayload, ['outbox_id' => $row['id'] ?? null]);
        if ($result->ok) {
            return true;
        }

        $this->logger?->warning('webhook-sender-failed', [
            'url' => $url,
            'status' => $result->httpStatus,
            'error' => $result->error,
        ]);

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}

