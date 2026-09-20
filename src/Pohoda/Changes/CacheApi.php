<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * HTTP read API over record_cache.
 */
class CacheApi
{
    private RecordCache $cache;

    private AccountingUnit $units;

    public function __construct(?RecordCache $cache = null, ?AccountingUnit $units = null)
    {
        $this->cache = $cache ?? new RecordCache();
        $this->units = $units ?? new AccountingUnit();
    }

    /**
     * Handle request; returns [status, headers, body].
     *
     * @param array<string, string> $query
     * @param array<string, string> $server
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public function handle(string $method, string $path, array $query = [], array $server = []): array
    {
        if (strtoupper($method) !== 'GET') {
            return [405, ['Content-Type' => 'text/plain'], "Method Not Allowed\n"];
        }

        $token = (string) \Ease\Shared::cfg('CACHE_API_TOKEN', '');

        if ($token !== '') {
            $provided = (string) ($server['HTTP_X_POHODA_TOKEN'] ?? $query['token'] ?? '');

            if (!hash_equals($token, $provided)) {
                return [401, ['Content-Type' => 'text/plain'], "Unauthorized\n"];
            }
        }

        $format = FormatNegotiator::resolve($query, $server, $path);
        $path = preg_replace('~\.(json|yaml|yml|xml)$~i', '', $path) ?? $path;

        if (!empty($query['document_uri'])) {
            try {
                $parsed = DocumentUri::validate((string) $query['document_uri']);
            } catch (\InvalidArgumentException $e) {
                return [400, ['Content-Type' => 'text/plain'], $e->getMessage()."\n"];
            }

            $ico = (string) ($parsed['args']['ico'] ?? '');
            $year = (int) ($parsed['args']['year'] ?? 0);
            $evidence = $parsed['document_type'];
            $recordId = (int) $parsed['record_id'];
            $variant = (string) ($query['variant'] ?? 'latest');

            return $this->respond($ico, $year, $evidence, $recordId, $variant, $format);
        }

        // /cache/{ico}/{year}/{evidence}/{recordid}[/previous|/history]
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));

        if (($parts[0] ?? '') === 'cache') {
            array_shift($parts);
        }

        if (\count($parts) < 4) {
            return [400, ['Content-Type' => 'text/plain'], "Usage: /cache/{ico}/{year}/{evidence}/{recordid}[/previous|/history]\n"];
        }

        [$ico, $yearStr, $evidence, $recordIdStr] = $parts;
        $variant = $parts[4] ?? 'latest';

        return $this->respond($ico, (int) $yearStr, $evidence, (int) $recordIdStr, $variant, $format);
    }

    /**
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function respond(string $ico, int $year, string $evidence, int $recordId, string $variant, string $format): array
    {
        $unit = $this->units->findByIcoYear($ico, $year);

        if ($unit === null) {
            return [404, ['Content-Type' => 'text/plain'], "Unknown accounting unit {$ico}/{$year}\n"];
        }

        $serverUrl = (string) $unit['serverurl'];

        if ($variant === 'history') {
            $all = $this->cache->loadAll($recordId, $evidence, $serverUrl);

            if ($all === []) {
                return [404, ['Content-Type' => 'text/plain'], "Not found\n"];
            }

            $rendered = FormatNegotiator::renderHistory($all, $format);

            return [200, ['Content-Type' => $rendered['content_type']], $rendered['body']];
        }

        $offset = $variant === 'previous' ? 1 : 0;
        $raw = $this->cache->loadNthRaw($recordId, $evidence, $serverUrl, $offset);

        if ($raw === null) {
            return [404, ['Content-Type' => 'text/plain'], "Not found\n"];
        }

        $data = $raw['data'];
        $data['_xml'] = $raw['xml'];
        $data['_inversion'] = $raw['inversion'];
        $data['_created'] = $raw['created'];

        $rendered = FormatNegotiator::render($data, $format);

        return [200, ['Content-Type' => $rendered['content_type']], $rendered['body']];
    }
}
