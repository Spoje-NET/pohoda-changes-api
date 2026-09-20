<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class WebhookTables extends AbstractMigration
{
    public function change(): void
    {
        $endpoints = $this->table('webhook_endpoints', ['id' => true, 'primary_key' => 'id']);
        $endpoints
            ->addColumn('url', 'string', ['limit' => 500])
            ->addColumn('secret', 'string', ['limit' => 200, 'null' => true, 'default' => null])
            ->addColumn('evidences', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON array or null=all'])
            ->addColumn('operations', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON array or null=all'])
            ->addColumn('icos', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON array or null=all'])
            ->addColumn('enabled', 'boolean', ['default' => true])
            ->addColumn('created', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();

        $deliveries = $this->table('webhook_deliveries', ['id' => true, 'primary_key' => 'id']);
        $deliveries
            ->addColumn('endpoint_id', 'integer', ['signed' => false])
            ->addColumn('change_inversion', 'integer', ['signed' => false])
            ->addColumn('source', 'integer', ['signed' => false])
            ->addColumn('evidence', 'string', ['limit' => 60])
            ->addColumn('recordid', 'integer', ['signed' => false])
            ->addColumn('status', 'string', ['limit' => 20])
            ->addColumn('http_code', 'integer', ['null' => true, 'default' => null])
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('last_error', 'text', ['null' => true, 'default' => null])
            ->addColumn('delivered_at', 'timestamp', ['null' => true, 'default' => null])
            ->addIndex(['endpoint_id', 'change_inversion', 'source', 'evidence', 'recordid'], ['name' => 'idx_delivery_lookup'])
            ->create();
    }
}
