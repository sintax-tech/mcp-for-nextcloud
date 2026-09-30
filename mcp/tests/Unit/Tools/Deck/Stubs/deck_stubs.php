<?php
declare(strict_types=1);

/**
 * Loads the Deck test doubles.
 *
 * A Deck test requires this before touching the module, because `nextcloud/ocp` ships only `OCP\*`
 * and `tests/bootstrap.php` is outside the directories this module owns.
 */
require_once __DIR__ . '/deck_entities.php';
require_once __DIR__ . '/deck_services.php';
