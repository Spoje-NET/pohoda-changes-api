<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Poll one agenda for one accounting unit via mServer lastChanges.
 */
class AgendaWatcher extends \Ease\Atom
{
    /** @var array<string, class-string<\mServer\Client>> */
    public const AGENDA_CLASSES = [
        'invoice' => \mServer\Invoice::class,
        'bank' => \mServer\Bank::class,
        'addressBook' => \mServer\Addressbook::class,
        'addressbook' => \mServer\Addressbook::class,
    ];

    private RecordCache $recordCache;

    private ChangeRecorder $changeRecorder;

    private \Ease\SQL\Engine $pollState;

    public function __construct(
        ?RecordCache $recordCache = null,
        ?ChangeRecorder $changeRecorder = null,
    ) {
        $this->recordCache = $recordCache ?? new RecordCache();
        $this->changeRecorder = $changeRecorder ?? new ChangeRecorder();
        $this->pollState = new \Ease\SQL\Engine(null, ['myTable' => 'poll_state']);
    }

    /**
     * @param array<string, mixed> $unit
     *
     * @return list<array<string, mixed>> recorded change payloads
     */
    public function poll(array $unit, string $agenda): array
    {
        $agendaKey = $agenda;
        $class = self::AGENDA_CLASSES[$agenda] ?? self::AGENDA_CLASSES[strtolower($agenda)] ?? null;

        if ($class === null) {
            $this->addStatusMessage('Unsupported agenda: '.$agenda, 'warning');

            return [];
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

        $watermark = $this->getWatermark((int) $unit['id'], $agendaKey);
        $filter = [];

        if ($watermark !== null) {
            $filter['lastChanges'] = $watermark;
        }

        $rows = $client->getColumnsFromPohoda(['id'], $filter) ?? [];
        $changes = [];
        $maxSeen = $watermark;
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s');
        $minVersions = (int) \Ease\Shared::cfg('RECORD_CACHE_MIN_VERSIONS', 2);

        foreach ($rows as $row) {
            $recordId = (int) ($row['id'] ?? 0);

            if ($recordId < 1) {
                continue;
            }

            $xml = $client->getPohodaXML($recordId);
            $client->loadFromPohoda($recordId);
            $document = $client->getData();

            if (!is_array($document) || $document === []) {
                $this->addStatusMessage(sprintf('Empty document %s#%d for %s', $agendaKey, $recordId, $unit['ico']), 'warning');

                continue;
            }

            $operation = $this->recordCache->hasAny($recordId, $agendaKey, (string) $unit['serverurl'])
                ? 'update'
                : 'create';

            $inversion = time();
            // ensure uniqueness within the same second
            usleep(1000);
            $inversion = max($inversion, time());

            $this->recordCache->store(
                $inversion,
                $recordId,
                $agendaKey,
                (string) $unit['serverurl'],
                $document,
                $xml,
            );
            $this->recordCache->prune($recordId, $agendaKey, (string) $unit['serverurl'], $minVersions);

            $recorded = $this->changeRecorder->record(
                $unit,
                $agendaKey,
                $recordId,
                $operation,
                $inversion,
                $document,
            );
            $changes[] = $recorded;
            $maxSeen = $now;
        }

        if ($maxSeen !== null) {
            $this->setWatermark((int) $unit['id'], $agendaKey, $maxSeen);
        } elseif ($watermark === null) {
            // first successful empty poll — seed watermark so next run is incremental
            $this->setWatermark((int) $unit['id'], $agendaKey, $now);
        }

        return $changes;
    }

    private function getWatermark(int $unitId, string $agenda): ?string
    {
        $row = $this->pollState->getFluentPDO()
            ->from('poll_state')
            ->where('unit_id', $unitId)
            ->where('agenda', $agenda)
            ->fetch();

        return isset($row['last_changes']) && $row['last_changes'] !== ''
            ? (string) $row['last_changes']
            : null;
    }

    private function setWatermark(int $unitId, string $agenda, string $lastChanges): void
    {
        $existing = $this->pollState->getFluentPDO()
            ->from('poll_state')
            ->where('unit_id', $unitId)
            ->where('agenda', $agenda)
            ->fetch();

        if ($existing) {
            $this->pollState->getFluentPDO()->update('poll_state')
                ->set(['last_changes' => $lastChanges, 'updated_at' => date('Y-m-d H:i:s')])
                ->where('id', $existing['id'])
                ->execute();
        } else {
            $this->pollState->getFluentPDO()->insertInto('poll_state')->values([
                'unit_id' => $unitId,
                'agenda' => $agenda,
                'last_changes' => $lastChanges,
            ])->execute();
        }
    }
}
