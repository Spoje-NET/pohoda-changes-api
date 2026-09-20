<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Persist a change event into changes_cache (MultiFlexi-compatible).
 */
class ChangeRecorder extends \Ease\SQL\Engine
{
    public string $myTable = 'changes_cache';

    public string $keyColumn = 'inversion';

    /**
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed> recorded row + cache_url
     */
    public function record(
        array $unit,
        string $evidence,
        int $recordId,
        string $operation,
        int $inversion,
        array $document = [],
        ?string $externalIds = null,
    ): array {
        $built = DocumentUriBuilder::build($unit, $evidence, $recordId, $document);
        $base = rtrim((string) \Ease\Shared::cfg('CACHE_PUBLIC_BASE', ''), '/');
        $cacheUrl = $base !== '' ? $base.'/cache/'.$built['cache_path'] : '/cache/'.$built['cache_path'];

        $row = [
            'inversion' => $inversion,
            'recordid' => $recordId,
            'evidence' => $evidence,
            'operation' => $operation,
            'externalids' => $externalIds,
            'source' => (int) $unit['id'],
            'target' => 'system',
            'document_uri' => $built['document_uri'],
            'context' => json_encode($built['context'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        ];

        try {
            $this->getFluentPDO()->insertInto($this->myTable)->values($row)->execute();
        } catch (\Throwable $e) {
            // duplicate key — update context/uri
            $this->getFluentPDO()->update($this->myTable)
                ->set([
                    'operation' => $operation,
                    'document_uri' => $row['document_uri'],
                    'context' => $row['context'],
                    'externalids' => $externalIds,
                ])
                ->where('inversion', $inversion)
                ->where('source', (int) $unit['id'])
                ->where('evidence', $evidence)
                ->where('recordid', $recordId)
                ->execute();
        }

        return array_merge($row, [
            'cache_url' => $cacheUrl,
            'context_decoded' => $built['context'],
            'ts' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        ]);
    }
}
