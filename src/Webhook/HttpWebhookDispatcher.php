<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Webhook;

use BlackCat\Messaging\Contracts\WebhookDispatcherInterface;
use BlackCat\Messaging\Support\WebhookDispatchResult;

final class HttpWebhookDispatcher implements WebhookDispatcherInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly string $userAgent = 'blackcat-webhook-outbox/1.0',
    ) {
    }

    public function dispatch(string $eventType, array $payload, array $meta = []): WebhookDispatchResult
    {
        unset($meta);

        $url = $this->readUrl($payload);
        if ($url === null) {
            return WebhookDispatchResult::failed('missing_webhook_url');
        }

        $method = strtoupper(trim((string)($payload['method'] ?? 'POST')));
        if ($method === '') {
            $method = 'POST';
        }

        /** @var array<string,mixed>|list<string> $headers */
        $headers = is_array($payload['headers'] ?? null) ? (array)$payload['headers'] : [];

        $body = $this->resolveBody($payload);
        $body['event_type'] = $body['event_type'] ?? $eventType;

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return WebhookDispatchResult::failed('json_encode_failed');
        }

        $curlHeaders = $this->normalizeHeaders($headers);
        $curlHeaders[] = 'Content-Type: application/json';
        $curlHeaders[] = 'User-Agent: ' . $this->userAgent;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => max(1, min(3, $this->timeoutSeconds)),
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $msg = $err !== '' ? $err : 'curl_failed';
            return WebhookDispatchResult::failed($msg, is_int($status) ? $status : null);
        }

        $code = is_int($status) ? $status : 0;
        if ($code >= 200 && $code < 300) {
            return WebhookDispatchResult::ok($code);
        }

        return WebhookDispatchResult::failed('http_' . $code, $code);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function readUrl(array $payload): ?string
    {
        $url = $payload['url'] ?? ($payload['webhook_url'] ?? ($payload['endpoint'] ?? null));
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        return $url !== '' ? $url : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resolveBody(array $payload): array
    {
        $body = $payload['body'] ?? null;
        if (is_array($body)) {
            return $body;
        }

        $inner = $payload['payload'] ?? null;
        if (is_array($inner)) {
            return $inner;
        }

        $copy = $payload;
        unset($copy['url'], $copy['webhook_url'], $copy['endpoint'], $copy['headers'], $copy['method']);
        return $copy;
    }

    /**
     * @param array<string,mixed>|list<string> $headers
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $out = [];

        $isList = array_keys($headers) === range(0, count($headers) - 1);
        if ($isList) {
            foreach ($headers as $h) {
                if (is_string($h) && trim($h) !== '') {
                    $out[] = trim($h);
                }
            }
            return $out;
        }

        foreach ($headers as $k => $v) {
            $name = trim((string)$k);
            if ($name === '') {
                continue;
            }
            if (is_array($v)) {
                continue;
            }
            $value = trim((string)$v);
            if ($value === '') {
                continue;
            }
            $out[] = $name . ': ' . $value;
        }

        return $out;
    }
}

