<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Optional per-unit poll mode override (mserver|mssql).
 */
final class PollModeColumn extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('changesapi');
        $table
            ->addColumn('poll_mode', 'string', [
                'limit' => 16,
                'null' => true,
                'default' => null,
                'after' => 'agendas',
            ])
            ->update();
    }
}
