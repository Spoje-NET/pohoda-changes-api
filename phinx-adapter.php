<?php

declare(strict_types=1);

/**
 * Phinx database adapter for pohoda-changes-api.
 */

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
} else {
    require_once __DIR__.'/../vendor/autoload.php';
}

$cfg = file_exists(__DIR__.'/.env') ? __DIR__.'/.env' : __DIR__.'/../.env';

\Ease\Shared::init(
    ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
    $cfg,
    true,
);

$dbtype = (string) \Ease\Shared::cfg('DB_CONNECTION', 'sqlite');
$prefix = file_exists(__DIR__.'/db/') ? __DIR__.'/db/' : __DIR__.'/../db/';

$sqlOptions = [];

if (str_contains($dbtype, 'sqlite')) {
    $sqlOptions['database'] = (string) \Ease\Shared::cfg('DB_DATABASE', sys_get_temp_dir().'/pohoda-changes.db');

    if (!file_exists($sqlOptions['database'])) {
        $dir = \dirname($sqlOptions['database']);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($sqlOptions['database'], '');
    }
}

$engine = new \Ease\SQL\Engine(null, $sqlOptions);

return [
    'paths' => [
        'migrations' => [$prefix.'migrations'],
        'seeds' => [$prefix.'seeds'],
    ],
    'environments' => [
        'default_environment' => 'development',
        'development' => [
            'adapter' => $dbtype,
            'name' => $engine->database,
            'connection' => $engine->getPdo($sqlOptions),
        ],
        'production' => [
            'adapter' => $dbtype,
            'name' => $engine->database,
            'connection' => $engine->getPdo($sqlOptions),
        ],
    ],
];
