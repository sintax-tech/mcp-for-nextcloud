<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Loads the test-only shapes of the Tables app classes the module names: the errors its services throw and the
 * application class that holds the node type constants. A Tables test requires this before touching the module, because
 * `nextcloud/ocp` ships only `OCP\*` and `tests/bootstrap.php` is outside the directories this module owns.
 */
require_once __DIR__ . '/tables_errors.php';
require_once __DIR__ . '/tables_application.php';
