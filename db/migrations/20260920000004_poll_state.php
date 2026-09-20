<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PollState extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('poll_state', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('unit_id', 'integer', ['signed' => false])
            ->addColumn('agenda', 'string', ['limit' => 60])
            ->addColumn('last_changes', 'string', ['limit' => 32, 'null' => true, 'default' => null])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['unit_id', 'agenda'], ['unique' => true, 'name' => 'uk_unit_agenda'])
            ->create();
    }
}
