<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Registry row for one Pohoda accounting unit (IČO + year + mServer URL).
 *
 * Table is named changesapi for MultiFlexi changes_cache.source compatibility.
 */
class AccountingUnit extends \Ease\SQL\Engine
{
    public string $myTable = 'changesapi';

    public string $keyColumn = 'id';

    /**
     * Build canonical instance key used in record_cache.serverurl.
     */
    public static function buildServerUrl(string $url, string $ico, int $year): string
    {
        return rtrim($url, '/').'#ico='.rawurlencode($ico).'&year='.$year;
    }

    /**
     * Default MSSQL database name for this unit.
     */
    public static function defaultDatabase(string $ico, int $year): string
    {
        return 'StwPh_'.$ico.'_'.$year;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return int inserted id
     */
    public function register(array $data): int
    {
        $ico = (string) ($data['ico'] ?? '');
        $year = (int) ($data['year'] ?? 0);
        $url = (string) ($data['url'] ?? '');

        if ($ico === '' || $year < 2000 || $url === '') {
            throw new \InvalidArgumentException('ico, year and url are required');
        }

        $row = [
            'ico' => $ico,
            'year' => $year,
            'url' => $url,
            'serverurl' => self::buildServerUrl($url, $ico, $year),
            'name' => $data['name'] ?? null,
            'username' => $data['username'] ?? null,
            'password' => $data['password'] ?? null,
            'db_host' => $data['db_host'] ?? null,
            'db_port' => $data['db_port'] ?? null,
            'db_database' => $data['db_database'] ?? self::defaultDatabase($ico, $year),
            'db_username' => $data['db_username'] ?? null,
            'db_password' => $data['db_password'] ?? null,
            'agendas' => isset($data['agendas']) ? (is_string($data['agendas']) ? $data['agendas'] : json_encode($data['agendas'])) : null,
            'poll_mode' => isset($data['poll_mode']) ? (string) $data['poll_mode'] : null,
            'enabled' => array_key_exists('enabled', $data) ? (int) (bool) $data['enabled'] : 1,
        ];

        $id = $this->insertToSQL($row);

        if (!is_numeric($id)) {
            throw new \RuntimeException('Failed to register accounting unit');
        }

        return (int) $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEnabled(): array
    {
        $rows = $this->getFluentPDO()
            ->from($this->myTable)
            ->where('enabled', 1)
            ->orderBy('ico')
            ->orderBy('year DESC')
            ->fetchAll();

        return $rows ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $rows = $this->getFluentPDO()
            ->from($this->myTable)
            ->orderBy('ico')
            ->orderBy('year DESC')
            ->fetchAll();

        return $rows ?: [];
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        return (bool) $this->updateToSQL(['enabled' => (int) $enabled], ['id' => $id]);
    }

    public function findByIcoYear(string $ico, int $year): ?array
    {
        $row = $this->getFluentPDO()
            ->from($this->myTable)
            ->where('ico', $ico)
            ->where('year', $year)
            ->fetch();

        return $row ?: null;
    }

    /**
     * Copy enabled units from $fromYear to $toYear (same url/credentials).
     *
     * @return int number of units created
     */
    public function rollover(int $fromYear, int $toYear, bool $disableOld = false): int
    {
        $created = 0;
        $sources = $this->getFluentPDO()
            ->from($this->myTable)
            ->where('year', $fromYear)
            ->where('enabled', 1)
            ->fetchAll() ?: [];

        foreach ($sources as $src) {
            if ($this->findByIcoYear((string) $src['ico'], $toYear)) {
                continue;
            }

            $this->register([
                'ico' => $src['ico'],
                'year' => $toYear,
                'url' => $src['url'],
                'name' => $src['name'],
                'username' => $src['username'],
                'password' => $src['password'],
                'db_host' => $src['db_host'],
                'db_port' => $src['db_port'],
                'db_database' => self::defaultDatabase((string) $src['ico'], $toYear),
                'db_username' => $src['db_username'],
                'db_password' => $src['db_password'],
                'agendas' => $src['agendas'],
                'poll_mode' => $src['poll_mode'] ?? null,
                'enabled' => true,
            ]);
            ++$created;

            if ($disableOld) {
                $this->setEnabled((int) $src['id'], false);
            }
        }

        return $created;
    }

    /**
     * Resolve mServer credentials for a unit row.
     *
     * @param array<string, mixed> $unit
     *
     * @return array{url: string, ico: string, user: string, password: string}
     */
    public static function mServerOptions(array $unit): array
    {
        return [
            'url' => (string) $unit['url'],
            'ico' => (string) $unit['ico'],
            'user' => (string) ($unit['username'] ?: \Ease\Shared::cfg('POHODA_USERNAME', '')),
            'password' => (string) ($unit['password'] ?: \Ease\Shared::cfg('POHODA_PASSWORD', '')),
        ];
    }

    /**
     * Resolve MSSQL connection options for spojenet/pohoda-sql (Ease FluentPDO).
     *
     * Per-unit db_* fields override global POHODA_MSSQL_* defaults.
     *
     * @param array<string, mixed> $unit
     *
     * @return array<string, mixed>
     */
    public static function mssqlOptions(array $unit): array
    {
        $host = (string) ($unit['db_host'] ?: \Ease\Shared::cfg('POHODA_MSSQL_HOST', ''));
        $database = (string) ($unit['db_database'] ?: self::defaultDatabase((string) $unit['ico'], (int) $unit['year']));
        $user = (string) ($unit['db_username'] ?: \Ease\Shared::cfg('POHODA_MSSQL_USER', ''));
        $pass = (string) ($unit['db_password'] ?: \Ease\Shared::cfg('POHODA_MSSQL_PASSWORD', ''));
        $port = $unit['db_port'] ?: \Ease\Shared::cfg('POHODA_MSSQL_PORT', 1433);
        $settings = (string) \Ease\Shared::cfg('POHODA_MSSQL_SETTINGS', 'TrustServerCertificate=yes');
        $settings = ltrim($settings, ';');

        if ($host === '' || $user === '') {
            throw new \RuntimeException(sprintf(
                'MSSQL credentials missing for unit %s/%s (set db_host/db_username or POHODA_MSSQL_*)',
                $unit['ico'] ?? '?',
                $unit['year'] ?? '?',
            ));
        }

        return [
            'dbType' => 'sqlsrv',
            'server' => $host,
            'port' => (string) $port,
            'database' => $database,
            'dbLogin' => $user,
            'dbPass' => $pass,
            // Ease FluentPDO prefixes a semicolon itself — do not add another
            'dbSettings' => $settings,
            'autoload' => false,
        ];
    }

    /**
     * Poll mode: mserver (default) or mssql.
     *
     * Default is always mserver — required for Pohoda editions without MSSQL.
     * mssql is an optional faster discovery path when StwPh_* is reachable.
     *
     * @param array<string, mixed> $unit
     */
    public static function pollModeFor(array $unit): string
    {
        $mode = strtolower((string) ($unit['poll_mode'] ?? \Ease\Shared::cfg('POLL_MODE', 'mserver')));

        return $mode === 'mssql' ? 'mssql' : 'mserver';
    }

    /**
     * @param array<string, mixed> $unit
     *
     * @return list<string>
     */
    public static function agendasFor(array $unit): array
    {
        if (!empty($unit['agendas'])) {
            $decoded = is_string($unit['agendas']) ? json_decode($unit['agendas'], true) : $unit['agendas'];

            if (is_array($decoded) && $decoded !== []) {
                return array_values(array_map('strval', $decoded));
            }
        }

        $cfg = (string) \Ease\Shared::cfg('POHODA_AGENDAS', 'invoice,bank,addressBook');

        return array_values(array_filter(array_map('trim', explode(',', $cfg))));
    }
}
