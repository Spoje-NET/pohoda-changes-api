<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Discover changes via mServer lastChanges filter.
 */
class MserverChangeSource extends \Ease\Sand implements ChangeIdSource
{
    /**
     * @param array<string, mixed> $unit
     *
     * @return list<array{id: int, changed_at: ?string}>
     */
    public function listChanged(array $unit, string $agenda, ?string $watermark): array
    {
        $class = AgendaWatcher::AGENDA_CLASSES[$agenda]
            ?? AgendaWatcher::AGENDA_CLASSES[strtolower($agenda)]
            ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException('Unsupported agenda: '.$agenda);
        }

        $opts = AccountingUnit::mServerOptions($unit);
        /** @var \mServer\Client $client */
        $client = new $class(null, [
            'url' => $opts['url'],
            'ico' => $opts['ico'],
            'user' => $opts['user'],
            'password' => $opts['password'],
            'debug' => (bool) \Ease\Shared::cfg('APP_DEBUG', false),
        ]);

        $filter = [];

        if ($watermark !== null) {
            $filter['lastChanges'] = $watermark;
        }

        $client->reset();
        $rows = $client->getColumnsFromPohoda(['id'], $filter) ?? [];
        $out = [];

        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $recordId = (int) ($row['id'] ?? $key);

            if ($recordId < 1) {
                continue;
            }

            $out[] = [
                'id' => $recordId,
                'changed_at' => null,
            ];
        }

        return $out;
    }
}
