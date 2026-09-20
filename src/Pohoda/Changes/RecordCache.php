<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Per-version document snapshot cache (MultiFlexi / AbraFlexi acceptor shape).
 */
class RecordCache extends \Ease\SQL\Engine
{
    public string $myTable = 'record_cache';

    public string $keyColumn = 'id';

    /**
     * @param array<string, mixed> $data
     */
    public function store(int $inversion, int $recordId, string $evidence, string $serverUrl, array $data, ?string $xml = null): void
    {
        $existing = $this->getFluentPDO()
            ->from($this->myTable)
            ->where('inversion', $inversion)
            ->where('recordid', $recordId)
            ->where('evidence', $evidence)
            ->where('serverurl', $serverUrl)
            ->fetch();

        $payload = [
            'inversion' => $inversion,
            'recordid' => $recordId,
            'evidence' => $evidence,
            'serverurl' => $serverUrl,
            'json' => json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'xml' => $xml,
        ];

        if ($existing) {
            $this->updateToSQL($payload, ['id' => $existing['id']]);
        } else {
            $this->insertToSQL($payload);
        }
    }

    /**
     * @return null|array<string, mixed>
     */
    public function loadLatest(int $recordId, string $evidence, string $serverUrl): ?array
    {
        return $this->loadNthVersion($recordId, $evidence, $serverUrl, 0);
    }

    /**
     * @return null|array<string, mixed>
     */
    public function loadPrevious(int $recordId, string $evidence, string $serverUrl): ?array
    {
        return $this->loadNthVersion($recordId, $evidence, $serverUrl, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadAll(int $recordId, string $evidence, string $serverUrl): array
    {
        $rows = $this->getFluentPDO()
            ->from($this->myTable)
            ->select('id, json, xml, inversion, created')
            ->where('recordid', $recordId)
            ->where('evidence', $evidence)
            ->where('serverurl', $serverUrl)
            ->orderBy('inversion DESC')
            ->fetchAll();

        $out = [];

        foreach ($rows ?: [] as $row) {
            $decoded = json_decode((string) $row['json'], true) ?? [];
            $out[] = array_merge(
                is_array($decoded) ? $decoded : [],
                [
                    '_inversion' => $row['inversion'],
                    '_created' => $row['created'],
                    '_xml' => $row['xml'] ?? null,
                ],
            );
        }

        return $out;
    }

    public function hasAny(int $recordId, string $evidence, string $serverUrl): bool
    {
        $row = $this->getFluentPDO()
            ->from($this->myTable)
            ->select('id')
            ->where('recordid', $recordId)
            ->where('evidence', $evidence)
            ->where('serverurl', $serverUrl)
            ->limit(1)
            ->fetch();

        return !empty($row);
    }

    /**
     * @return null|array{json: string, xml: ?string, inversion: int, created: mixed, data: array<string, mixed>}
     */
    public function loadNthRaw(int $recordId, string $evidence, string $serverUrl, int $offset): ?array
    {
        $row = $this->getFluentPDO()
            ->from($this->myTable)
            ->select('json, xml, inversion, created')
            ->where('recordid', $recordId)
            ->where('evidence', $evidence)
            ->where('serverurl', $serverUrl)
            ->orderBy('inversion DESC')
            ->limit(1)
            ->offset($offset)
            ->fetch();

        if (empty($row['json'])) {
            return null;
        }

        $decoded = json_decode((string) $row['json'], true);

        return [
            'json' => (string) $row['json'],
            'xml' => isset($row['xml']) ? (string) $row['xml'] : null,
            'inversion' => (int) $row['inversion'],
            'created' => $row['created'],
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function loadNthVersion(int $recordId, string $evidence, string $serverUrl, int $offset): ?array
    {
        $raw = $this->loadNthRaw($recordId, $evidence, $serverUrl, $offset);

        return $raw['data'] ?? null;
    }

    /**
     * Keep at most $minVersions snapshots per document (newest kept).
     */
    public function prune(int $recordId, string $evidence, string $serverUrl, int $minVersions = 2): int
    {
        $rows = $this->getFluentPDO()
            ->from($this->myTable)
            ->select('id')
            ->where('recordid', $recordId)
            ->where('evidence', $evidence)
            ->where('serverurl', $serverUrl)
            ->orderBy('inversion DESC')
            ->fetchAll() ?: [];

        if (\count($rows) <= $minVersions) {
            return 0;
        }

        $deleted = 0;

        foreach (\array_slice($rows, $minVersions) as $row) {
            $this->deleteFromSQL(['id' => $row['id']]);
            ++$deleted;
        }

        return $deleted;
    }
}
