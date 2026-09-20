<?php

declare(strict_types=1);

/**
 * Pohoda Changes API — cache HTTP front controller.
 */

namespace Pohoda\Changes;

\define('APP_NAME', 'PohodaChangesApi');

require_once __DIR__.'/../vendor/autoload.php';

\Ease\Shared::init(
    ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
    __DIR__.'/../.env',
);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';
// strip optional base path
$scriptDir = rtrim(\dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

if ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
    $path = substr($path, \strlen($scriptDir)) ?: '/';
}

$api = new CacheApi();
[$status, $headers, $body] = $api->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $path,
    $_GET,
    $_SERVER,
);

http_response_code($status);

foreach ($headers as $name => $value) {
    header($name.': '.$value);
}

echo $body;
