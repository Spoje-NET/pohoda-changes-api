<?php

declare(strict_types=1);

namespace Pohoda\Changes\Webhook;

/**
 * Fan-out HTTP POST deliveries for a recorded change.
 */
class Dispatcher extends \Ease\Sand
{
    private Endpoint $endpoints;

    private DeliveryLog $log;

    private int $maxAttempts;

    public function __construct(
        ?Endpoint $endpoints = null,
        ?DeliveryLog $log = null,
        int $maxAttempts = 3,
    ) {
        $this->endpoints = $endpoints ?? new Endpoint();
        $this->log = $log ?? new DeliveryLog();
        $this->maxAttempts = $maxAttempts;
    }

    /**
     * @param array<string, mixed> $change from ChangeRecorder::record()
     * @param array<string, mixed> $unit
     */
    public function dispatch(array $change, array $unit): void
    {
        $payload = [
            'event' => 'webhook.change',
            'ts' => $change['ts'] ?? (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            'source_system' => 'pohoda',
            'source' => (int) $change['source'],
            'evidence' => $change['evidence'],
            'operation' => $change['operation'],
            'recordid' => (int) $change['recordid'],
            'inversion' => (int) $change['inversion'],
            'document_uri' => $change['document_uri'],
            'cache_url' => $change['cache_url'] ?? null,
            'context' => $change['context_decoded']
                ?? (is_string($change['context'] ?? null) ? json_decode((string) $change['context'], true) : $change['context'] ?? []),
        ];

        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        foreach ($this->endpoints->listEnabled() as $endpoint) {
            if (!$this->endpoints->matches($endpoint, $change, $unit)) {
                continue;
            }

            $this->deliver((int) $endpoint['id'], (string) $endpoint['url'], $endpoint['secret'] ?? null, $body ?: '{}', $change);
        }
    }

    /**
     * @param array<string, mixed> $change
     */
    private function deliver(int $endpointId, string $url, ?string $secret, string $body, array $change): void
    {
        $attempt = 0;
        $httpCode = null;
        $error = null;
        $ok = false;

        while ($attempt < $this->maxAttempts && !$ok) {
            ++$attempt;
            $ch = curl_init($url);
            $headers = [
                'Content-Type: application/json',
                'User-Agent: pohoda-changes-api/1.0',
            ];

            if ($secret !== null && $secret !== '') {
                $headers[] = 'X-Pohoda-Token: '.$secret;
            }

            curl_setopt_array($ch, [
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => $body,
                \CURLOPT_HTTPHEADER => $headers,
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT => 30,
                \CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $error = $curlErr ?: 'curl error';
            } elseif ($httpCode >= 200 && $httpCode < 300) {
                $ok = true;
                $error = null;
            } else {
                $error = 'HTTP '.$httpCode;
            }

            if (!$ok && $attempt < $this->maxAttempts) {
                usleep(200000 * $attempt);
            }
        }

        $this->log->write([
            'endpoint_id' => $endpointId,
            'change_inversion' => (int) $change['inversion'],
            'source' => (int) $change['source'],
            'evidence' => (string) $change['evidence'],
            'recordid' => (int) $change['recordid'],
            'status' => $ok ? 'success' : 'failed',
            'http_code' => $httpCode,
            'attempts' => $attempt,
            'last_error' => $error,
            'delivered_at' => $ok ? date('Y-m-d H:i:s') : null,
        ]);

        if (!$ok) {
            $this->addStatusMessage(sprintf('Webhook delivery to %s failed: %s', $url, $error ?? 'unknown'), 'error');
        }
    }
}
