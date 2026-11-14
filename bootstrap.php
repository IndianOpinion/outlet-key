<?php

/**
 * Use outlet-key without Composer:
 *
 *     require '/path/to/outlet-key/bootstrap.php';
 *
 * Registers an autoloader for the IndianOpinion\OutletKey namespace and
 * nothing else.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\OutletKey\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
