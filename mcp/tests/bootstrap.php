<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
// nextcloud/ocp ships interfaces without an autoloader of its own, and a few of them extend server classes that
// the package does not ship either. OC\ is served first because those few are the reason the OCP interface cannot
// even be loaded.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'OC\\')) {
        $file = __DIR__ . '/stubs/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
    if (str_starts_with($class, 'OCP\\')) {
        $file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
