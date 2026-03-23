<?php

/**
 * Manual PSR-4 autoloader for non-Composer environments.
 *
 * Usage:
 *   require_once __DIR__ . '/autoload.php';
 *   $client = new \Xident\SDK\Client('sk_live_xxx');
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Xident\\SDK\\';
    $prefixLen = strlen($prefix);

    if (strncmp($class, $prefix, $prefixLen) !== 0) {
        return;
    }

    $relativeClass = substr($class, $prefixLen);
    $file = __DIR__ . '/src/Xident/SDK/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
