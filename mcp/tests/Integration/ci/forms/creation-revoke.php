<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Changes only the fixed synthetic actor's create grant in a separate disposable-test process. */
require dirname(__DIR__, 2) . '/bootstrap.php';
\OCP\Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class)->setGrant(\OCA\Mcp\Tests\Integration\Forms\FormsScene::READER, 'forms', 'create', false);
echo 'revoked';
