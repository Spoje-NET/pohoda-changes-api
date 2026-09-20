<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Poll one agenda for one accounting unit.
 *
 * Modes ({@see AccountingUnit::pollModeFor}):
 * - mserver — discover via lastChanges, load via mServer
 * - mssql   — discover via DatSave (spojenet/pohoda-sql), load via mServer
 */
class AgendaWatcher extends \Ease\Sand
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

    private MserverDocumentLoader $documentLoader;

    public function __construct(
        ?RecordCache $recordCache = null,
        ?ChangeRecorder $changeRecorder = null,
        ?MserverDocumentLoader $documentLoader = null,
    ) {
        $this->recordCache = $recordCache ?? new RecordCache();
        $this->changeRecorder = $changeRecorder ?? new ChangeRecorder();
        $this->pollState = new \Ease\SQL\Engine(null, ['myTable' => 'poll_state']);
        $this->documentLoader = $documentLoader ?? new MserverDocumentLoader();
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

        $mode = AccountingUnit::pollModeFor($unit);
        $watermark = $this->getWatermark((int) $unit['id'], $agendaKey);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s');
        $minVersions = (int) \Ease\Shared::cfg('RECORD_CACHE_MIN_VERSIONS', 2);

        if ($mode === 'mssql'
            && $watermark === null
            && filter_var(\Ease\Shared::cfg('POLL_MSSQL_SEED_ONLY', false), \FILTER_VALIDATE_BOOLEAN)
        ) {
            $seed = $this->mssqlSource()->seedWatermark($unit, $agendaKey);

            if ($seed !== null) {
                $this->setWatermark((int) $unit['id'], $agendaKey, $seed);
                $this->addStatusMessage(
                    sprintf('MSSQL watermark seeded for %s/%s at %s (no historical export)', $unit['ico'], $agendaKey, $seed),
                    'info',
                );
            } else {
                $this->setWatermark((int) $unit['id'], $agendaKey, $now);
            }

            return [];
        }

        try {
            $source = $this->changeSourceFor($mode);
            $changed = $source->listChanged($unit, $agendaKey, $watermark);
        } catch (\Throwable $e) {
            $this->addStatusMessage(
                sprintf('Change discovery failed (%s) for %s/%s: %s', $mode, $unit['ico'], $agendaKey, $e->getMessage()),
                'error',
            );

            return [];
        }

        $changes = [];
        $maxSeen = $watermark;

        foreach ($changed as $row) {
            $recordId = (int) ($row['id'] ?? 0);

            if ($recordId < 1) {
                continue;
            }

            $loaded = $this->documentLoader->load($unit, $agendaKey, $recordId);
            $document = $loaded['document'];
            $xml = $loaded['xml'];

            if (!is_array($document) || $document === []) {
                continue;
            }

            $operation = $this->recordCache->hasAny($recordId, $agendaKey, (string) $unit['serverurl'])
                ? 'update'
                : 'create';

            $inversion = time();
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

            if (!empty($row['changed_at'])) {
                $candidate = (string) $row['changed_at'];
                $maxSeen = ($maxSeen === null || strcmp($candidate, $maxSeen) > 0) ? $candidate : $maxSeen;
            } else {
                $maxSeen = $now;
            }
        }

        if ($maxSeen !== null) {
            $this->setWatermark((int) $unit['id'], $agendaKey, $maxSeen);
        } elseif ($watermark === null) {
            $this->setWatermark((int) $unit['id'], $agendaKey, $now);
        }

        return $changes;
    }

    protected function changeSourceFor(string $mode): ChangeIdSource
    {
        return $mode === 'mssql' ? $this->mssqlSource() : new MserverChangeSource();
    }

    protected function mssqlSource(): MssqlChangeSource
    {
        return new MssqlChangeSource();
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
