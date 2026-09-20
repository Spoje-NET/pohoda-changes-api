<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * MultiFlexi-compatible change event cache.
 */
final class ChangesCache extends AbstractMigration
{
    public function change(): void
    {
        $isSqlite = $this->getAdapter()->getOption('adapter') === 'sqlite';

        $table = $this->table('changes_cache', ['id' => false, 'primary_key' => ['inversion', 'source', 'evidence', 'recordid']]);
        $table
            ->addColumn('inversion', 'integer', ['signed' => false])
            ->addColumn('recordid', 'integer', ['signed' => false])
            ->addColumn('evidence', 'string', ['limit' => 60])
            ->addColumn('operation', $isSqlite ? 'string' : 'enum', $isSqlite
                ? ['limit' => 10]
                : ['values' => ['create', 'update', 'delete']])
            ->addColumn('externalids', 'string', ['limit' => 300, 'null' => true, 'default' => null])
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('source', 'integer', ['signed' => false, 'comment' => 'FK changesapi.id'])
            ->addColumn('target', 'string', ['limit' => 30, 'default' => 'system'])
            ->addColumn('document_uri', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('context', 'text', ['null' => true, 'default' => null])
            ->addIndex(['document_uri'], ['name' => 'idx_changes_cache_document_uri'])
            ->addIndex(['source', 'evidence'], ['name' => 'idx_changes_source_evidence'])
            ->create();
    }
}
