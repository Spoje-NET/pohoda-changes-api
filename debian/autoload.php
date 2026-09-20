<?php

declare(strict_types=1);

/**
 * Debian autoloader for pohoda-changes-api
 */

require_once '/usr/share/php/Composer/InstalledVersions.php';
require_once '/usr/share/php/Ease/autoload.php';
require_once '/usr/share/php/EaseFluentPDO/autoload.php';
require_once '/usr/share/php/mServer/autoload.php';
require_once '/usr/share/php/Pohoda/autoload.php';
require_once '/usr/share/php/Symfony/Component/Yaml/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Pohoda\\Changes\\';
    $baseDir = '/usr/share/pohoda-changes-api/src/Pohoda/Changes/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $baseDir.str_replace('\\', '/', $relative).'.php';

    if (file_exists($file)) {
        require $file;
    }
});

(function (): void {
    $versions = [];

    foreach (\Composer\InstalledVersions::getAllRawData() as $d) {
        $versions = array_merge($versions, $d['versions'] ?? []);
    }

    $name = 'unknown';
    $version = '0.0.0';
    $versions[$name] = [
        'pretty_version' => $version,
        'version' => $version,
        'reference' => null,
        'type' => 'library',
        'install_path' => __DIR__,
        'aliases' => [],
        'dev_requirement' => false,
    ];
    \Composer\InstalledVersions::reload([
        'root' => [
            'name' => $name,
            'pretty_version' => $version,
            'version' => $version,
            'reference' => null,
            'type' => 'library',
            'install_path' => __DIR__,
            'aliases' => [],
            'dev' => false,
        ],
        'versions' => $versions,
    ]);
})();
