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
