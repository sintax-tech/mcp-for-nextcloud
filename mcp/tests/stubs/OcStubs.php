<?php
declare(strict_types=1);

// Private core interfaces referenced by public OCP interfaces; nextcloud/ocp does not ship them.

namespace OC\Hooks;

if (!interface_exists(Emitter::class)) {
    interface Emitter {
        public function listen($scope, $method, callable $callback);
        public function removeListener($scope = null, $method = null, ?callable $callback = null);
    }
}
