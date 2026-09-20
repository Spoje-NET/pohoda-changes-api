<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Per-version document snapshots for cache API and previous/current diffs.
 */
final class RecordCache extends AbstractMigration
{
    public function change(): void
    {
        $adapter = $this->getAdapter()->getOption('adapter');
        $jsonOpts = ['null' => false];
        $xmlOpts = ['null' => true, 'default' => null];

        if ($adapter === 'mysql') {
            $jsonOpts['limit'] = MysqlAdapter::TEXT_LONG;
            $xmlOpts['limit'] = MysqlAdapter::TEXT_LONG;
        }

        $table = $this->table('record_cache', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('inversion', 'integer', ['signed' => false])
            ->addColumn('recordid', 'integer', ['signed' => false])
            ->addColumn('evidence', 'string', ['limit' => 60])
            ->addColumn('serverurl', 'string', ['limit' => 400])
            ->addColumn('json', 'text', $jsonOpts)
            ->addColumn('xml', 'text', $xmlOpts)
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['inversion', 'recordid', 'evidence', 'serverurl'], ['unique' => true, 'name' => 'uk_record_version'])
            ->addIndex(['recordid', 'evidence', 'serverurl'], ['name' => 'idx_document'])
            ->create();
    }
}
