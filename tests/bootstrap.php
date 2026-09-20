<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

$testDb = sys_get_temp_dir().'/pohoda-changes-test-'.getmypid().'.db';

if (file_exists($testDb)) {
    unlink($testDb);
}

putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$testDb);
putenv('DB_HOST=');
putenv('DB_PORT=');
putenv('DB_USERNAME=');
putenv('DB_PASSWORD=');
putenv('CACHE_PUBLIC_BASE=http://localhost/pohoda-changes-api');
putenv('POHODA_AGENDAS=invoice,bank,addressBook');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $testDb;
$_ENV['CACHE_PUBLIC_BASE'] = 'http://localhost/pohoda-changes-api';

\Ease\Shared::init(
    ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
    null,
    true,
);

// Apply migrations via PDO
$pdo = new PDO('sqlite:'.$testDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(<<<'SQL'
CREATE TABLE changesapi (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ico TEXT NOT NULL,
  year INTEGER NOT NULL,
  url TEXT NOT NULL,
  serverurl TEXT NOT NULL UNIQUE,
  name TEXT,
  username TEXT,
  password TEXT,
  db_host TEXT,
  db_port INTEGER,
  db_database TEXT,
  db_username TEXT,
  db_password TEXT,
  agendas TEXT,
  poll_mode TEXT,
  enabled INTEGER DEFAULT 1,
  created TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(ico, year)
);
CREATE TABLE changes_cache (
  inversion INTEGER NOT NULL,
  recordid INTEGER NOT NULL,
  evidence TEXT NOT NULL,
  operation TEXT NOT NULL,
  externalids TEXT,
  created TEXT DEFAULT CURRENT_TIMESTAMP,
  source INTEGER NOT NULL,
  target TEXT DEFAULT 'system',
  document_uri TEXT,
  context TEXT,
  PRIMARY KEY (inversion, source, evidence, recordid)
);
CREATE TABLE record_cache (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  inversion INTEGER NOT NULL,
  recordid INTEGER NOT NULL,
  evidence TEXT NOT NULL,
  serverurl TEXT NOT NULL,
  json TEXT NOT NULL,
  xml TEXT,
  created TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(inversion, recordid, evidence, serverurl)
);
CREATE TABLE poll_state (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  unit_id INTEGER NOT NULL,
  agenda TEXT NOT NULL,
  last_changes TEXT,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(unit_id, agenda)
);
CREATE TABLE webhook_endpoints (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  url TEXT NOT NULL,
  secret TEXT,
  evidences TEXT,
  operations TEXT,
  icos TEXT,
  enabled INTEGER DEFAULT 1,
  created TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE webhook_deliveries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  endpoint_id INTEGER NOT NULL,
  change_inversion INTEGER NOT NULL,
  source INTEGER NOT NULL,
  evidence TEXT NOT NULL,
  recordid INTEGER NOT NULL,
  status TEXT NOT NULL,
  http_code INTEGER,
  attempts INTEGER DEFAULT 0,
  last_error TEXT,
  delivered_at TEXT
);
SQL);

/**
 * Reset application tables between tests (shared sqlite file).
 */
function pohoda_changes_test_reset(): void
{
    $engine = new \Ease\SQL\Engine();
    $pdo = $engine->getPdo();

    foreach ([
        'webhook_deliveries',
        'webhook_endpoints',
        'poll_state',
        'record_cache',
        'changes_cache',
        'changesapi',
    ] as $table) {
        $pdo->exec('DELETE FROM '.$table);
    }
}

register_shutdown_function(static function () use ($testDb): void {
    if (file_exists($testDb)) {
        @unlink($testDb);
    }
});
