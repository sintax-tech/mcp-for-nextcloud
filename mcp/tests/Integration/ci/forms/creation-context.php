<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Runs the focused disposable context fixture through real Nextcloud; no product code lives here. */
require dirname(__DIR__, 2) . '/bootstrap.php';
set_exception_handler(static function (\Throwable $error): void { fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n"); exit(1); });
echo json_encode((new \OCA\Mcp\Tests\Integration\Forms\Creation\CreationContextProbe())->run($argv[1]), JSON_THROW_ON_ERROR) . "\n";
