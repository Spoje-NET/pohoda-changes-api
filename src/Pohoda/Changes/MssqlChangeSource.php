<?php

declare(strict_types=1);

namespace Pohoda\Changes;

use SpojeNet\PohodaSQL\Adresar;
use SpojeNet\PohodaSQL\Agenda;
use SpojeNet\PohodaSQL\BankovniVypis;
use SpojeNet\PohodaSQL\Faktura;

/**
 * Discover changes by polling Pohoda MSSQL DatSave via spojenet/pohoda-sql.
 *
 * mServer is not used here — only ID + timestamp discovery.
 */
class MssqlChangeSource extends \Ease\Sand implements ChangeIdSource
{
    /** @var array<string, class-string<Agenda>> */
    public const AGENDA_CLASSES = [
        'invoice' => Faktura::class,
        'bank' => BankovniVypis::class,
        'addressBook' => Adresar::class,
        'addressbook' => Adresar::class,
    ];

    /**
     * @param array<string, mixed> $unit
     *
     * @return list<array{id: int, changed_at: ?string}>
     */
    public function listChanged(array $unit, string $agenda, ?string $watermark): array
    {
        $table = $this->agenda($unit, $agenda);
        $limit = (int) \Ease\Shared::cfg('POLL_MSSQL_LIMIT', 0);

        try {
            return $table->idsChangedSince($watermark, $limit > 0 ? $limit : null);
        } finally {
            $table->pdo = null;
            $table->fluent = null;
        }
    }

    /**
     * @param array<string, mixed> $unit
     */
    public function seedWatermark(array $unit, string $agenda): ?string
    {
        $table = $this->agenda($unit, $agenda);

        try {
            return $table->maxChangedAt();
        } finally {
            $table->pdo = null;
            $table->fluent = null;
        }
    }

    /**
     * @param array<string, mixed> $unit
     */
    private function agenda(array $unit, string $agenda): Agenda
    {
        $class = self::AGENDA_CLASSES[$agenda]
            ?? self::AGENDA_CLASSES[strtolower($agenda)]
            ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException('Unsupported agenda for MSSQL: '.$agenda);
        }

        $opts = AccountingUnit::mssqlOptions($unit);
        /** @var Agenda $table */
        $table = new $class(null, $opts);
        // Ease\SQL\Engine::pdoConnect([]) re-reads Shared DB_* (app sqlite) and would
        // overwrite sqlsrv — connect explicitly with the MSSQL options.
        $table->pdo = null;
        $table->pdo = $table->pdoConnect($opts);

        return $table;
    }
}
