<?php

declare(strict_types=1);

namespace Pohoda\Changes\Tests;

use PHPUnit\Framework\TestCase;
use Pohoda\Changes\AccountingUnit;
use Pohoda\Changes\AgendaWatcher;
use Pohoda\Changes\ChangeIdSource;
use Pohoda\Changes\MserverDocumentLoader;
use Pohoda\Changes\MssqlChangeSource;

final class MssqlPollModeTest extends TestCase
{
    protected function setUp(): void
    {
        pohoda_changes_test_reset();
        putenv('POLL_MODE=mserver');
        $_ENV['POLL_MODE'] = 'mserver';
    }

    public function testPollModeFromEnvAndUnit(): void
    {
        putenv('POLL_MODE=mssql');
        $_ENV['POLL_MODE'] = 'mssql';
        self::assertSame('mssql', AccountingUnit::pollModeFor([]));

        self::assertSame('mserver', AccountingUnit::pollModeFor(['poll_mode' => 'mserver']));
        self::assertSame('mssql', AccountingUnit::pollModeFor(['poll_mode' => 'mssql']));
    }

    public function testMssqlOptionsPreferUnitOverEnv(): void
    {
        putenv('POHODA_MSSQL_HOST=env-host');
        putenv('POHODA_MSSQL_USER=env-user');
        putenv('POHODA_MSSQL_PASSWORD=env-pass');
        $_ENV['POHODA_MSSQL_HOST'] = 'env-host';
        $_ENV['POHODA_MSSQL_USER'] = 'env-user';
        $_ENV['POHODA_MSSQL_PASSWORD'] = 'env-pass';

        $opts = AccountingUnit::mssqlOptions([
            'ico' => '12345678',
            'year' => 2026,
            'db_host' => '10.11.25.23',
            'db_port' => 1433,
            'db_username' => 'pohoda',
            'db_password' => 'secret',
            'db_database' => 'StwPh_12345678_2026',
        ]);

        self::assertSame('sqlsrv', $opts['dbType']);
        self::assertSame('10.11.25.23', $opts['server']);
        self::assertSame('pohoda', $opts['dbLogin']);
        self::assertSame('secret', $opts['dbPass']);
        self::assertSame('StwPh_12345678_2026', $opts['database']);
    }

    public function testMssqlOptionsRequireHost(): void
    {
        putenv('POHODA_MSSQL_HOST');
        putenv('POHODA_MSSQL_USER');
        unset($_ENV['POHODA_MSSQL_HOST'], $_ENV['POHODA_MSSQL_USER']);

        $this->expectException(\RuntimeException::class);
        AccountingUnit::mssqlOptions(['ico' => '1', 'year' => 2026]);
    }

    public function testPollDiscoversViaMssqlSourceAndLoadsViaMserver(): void
    {
        $units = new AccountingUnit();
        $unitId = $units->register([
            'ico' => '12345678',
            'year' => 2026,
            'url' => 'http://127.0.0.1:40000',
            'poll_mode' => 'mssql',
        ]);
        $unit = $units->getFluentPDO()->from('changesapi')->where('id', $unitId)->fetch();
        self::assertIsArray($unit);

        $source = new class implements ChangeIdSource {
            public function listChanged(array $unit, string $agenda, ?string $watermark): array
            {
                return [
                    ['id' => 42, 'changed_at' => '2026-09-20T10:00:00'],
                ];
            }
        };

        $loader = new class extends MserverDocumentLoader {
            public function load(array $unit, string $agenda, int $recordId): array
            {
                return [
                    'document' => ['id' => (string) $recordId, 'number' => 'FA1'],
                    'xml' => '<invoice/>',
                ];
            }
        };

        $watcher = new class($source, $loader) extends AgendaWatcher {
            public string $seenMode = '';

            public function __construct(
                private ChangeIdSource $forcedSource,
                MserverDocumentLoader $loader,
            ) {
                parent::__construct(null, null, $loader);
            }

            protected function changeSourceFor(string $mode): ChangeIdSource
            {
                $this->seenMode = $mode;

                return $this->forcedSource;
            }
        };

        $changes = $watcher->poll($unit, 'invoice');
        self::assertSame('mssql', $watcher->seenMode);
        self::assertCount(1, $changes);
        self::assertSame(42, $changes[0]['recordid']);
        self::assertSame('create', $changes[0]['operation']);

        $state = (new \Ease\SQL\Engine(null, ['myTable' => 'poll_state']))
            ->getFluentPDO()
            ->from('poll_state')
            ->where('unit_id', $unitId)
            ->where('agenda', 'invoice')
            ->fetch();
        self::assertSame('2026-09-20T10:00:00', $state['last_changes']);
    }

    public function testMssqlAgendaMapping(): void
    {
        self::assertSame(
            \SpojeNet\PohodaSQL\Faktura::class,
            MssqlChangeSource::AGENDA_CLASSES['invoice'],
        );
        self::assertSame(
            \SpojeNet\PohodaSQL\BankovniVypis::class,
            MssqlChangeSource::AGENDA_CLASSES['bank'],
        );
        self::assertSame(
            \SpojeNet\PohodaSQL\Adresar::class,
            MssqlChangeSource::AGENDA_CLASSES['addressBook'],
        );
    }
}
