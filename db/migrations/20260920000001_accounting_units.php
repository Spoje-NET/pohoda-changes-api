<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Registry of Pohoda accounting units (IČO + year + mServer URL).
 * Named changesapi for MultiFlexi source FK compatibility.
 */
final class AccountingUnits extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('changesapi', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('ico', 'string', ['limit' => 20])
            ->addColumn('year', 'integer')
            ->addColumn('url', 'string', ['limit' => 300])
            ->addColumn('serverurl', 'string', ['limit' => 400])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => true, 'default' => null])
            ->addColumn('username', 'string', ['limit' => 120, 'null' => true, 'default' => null])
            ->addColumn('password', 'string', ['limit' => 120, 'null' => true, 'default' => null])
            ->addColumn('db_host', 'string', ['limit' => 120, 'null' => true, 'default' => null])
            ->addColumn('db_port', 'integer', ['null' => true, 'default' => null])
            ->addColumn('db_database', 'string', ['limit' => 120, 'null' => true, 'default' => null])
            ->addColumn('db_username', 'string', ['limit' => 120, 'null' => true, 'default' => null])
            ->addColumn('db_password', 'string', ['limit' => 120, 'null' => true, 'default' => null])
            ->addColumn('agendas', 'text', ['null' => true, 'default' => null])
            ->addColumn('enabled', 'boolean', ['default' => true])
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['ico', 'year'], ['unique' => true, 'name' => 'uk_ico_year'])
            ->addIndex(['serverurl'], ['unique' => true, 'name' => 'uk_serverurl'])
            ->create();
    }
}
