<?php
declare(strict_types=1);

namespace OC\Hooks;

/**
 * The emitter contract nextcloud/ocp expects from the server classes its interfaces extend.
 *
 * OCP\Files\IRootFolder extends this, so the interface cannot even be loaded without it. Only the two methods a
 * caller of the emitter uses are declared here; the real implementation lives in the server, which the unit tests
 * never load.
 */
interface Emitter {
    /**
     * @param string $event Event name
     * @param callable $callback Callback invoked when the event happens
     */
    public function listen(string $event, callable $callback): void;

    /**
     * @param string $event Event name
     * @param callable $callback Callback to remove
     */
    public function removeListener(string $event, callable $callback): void;
}
