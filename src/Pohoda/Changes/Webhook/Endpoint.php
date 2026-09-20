<?php

declare(strict_types=1);

namespace Pohoda\Changes\Webhook;

/**
 * Registered outbound webhook endpoint.
 */
class Endpoint extends \Ease\SQL\Engine
{
    public string $myTable = 'webhook_endpoints';

    public string $keyColumn = 'id';

    /**
     * @param array<string, mixed> $data
     */
    public function register(array $data): int
    {
        $url = (string) ($data['url'] ?? '');

        if ($url === '' || !filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Valid url is required');
        }

        $id = $this->insertToSQL([
            'url' => $url,
            'secret' => $data['secret'] ?? null,
            'evidences' => isset($data['evidences']) ? $this->encodeList($data['evidences']) : null,
            'operations' => isset($data['operations']) ? $this->encodeList($data['operations']) : null,
            'icos' => isset($data['icos']) ? $this->encodeList($data['icos']) : null,
            'enabled' => array_key_exists('enabled', $data) ? (int) (bool) $data['enabled'] : 1,
        ]);

        if (!is_numeric($id)) {
            throw new \RuntimeException('Failed to register webhook endpoint');
        }

        return (int) $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEnabled(): array
    {
        return $this->getFluentPDO()
            ->from($this->myTable)
            ->where('enabled', 1)
            ->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->getFluentPDO()
            ->from($this->myTable)
            ->orderBy('id')
            ->fetchAll() ?: [];
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        return (bool) $this->updateToSQL(['enabled' => (int) $enabled], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $endpoint
     * @param array<string, mixed> $change
     * @param array<string, mixed> $unit
     */
    public function matches(array $endpoint, array $change, array $unit): bool
    {
        $evidences = $this->decodeList($endpoint['evidences'] ?? null);

        if ($evidences !== null && !\in_array((string) $change['evidence'], $evidences, true)) {
            return false;
        }

        $operations = $this->decodeList($endpoint['operations'] ?? null);

        if ($operations !== null && !\in_array((string) $change['operation'], $operations, true)) {
            return false;
        }

        $icos = $this->decodeList($endpoint['icos'] ?? null);

        if ($icos !== null && !\in_array((string) $unit['ico'], $icos, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param mixed $value
     */
    private function encodeList($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            if (str_starts_with(trim($value), '[')) {
                return $value;
            }

            $value = array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return json_encode(array_values($value), \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return null|list<string>
     */
    private function decodeList(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (!is_array($decoded)) {
            return null;
        }

        return array_values(array_map('strval', $decoded));
    }
}
