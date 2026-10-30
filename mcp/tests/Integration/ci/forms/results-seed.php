<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Runs only the focused disposable OCS results scene setup. */
require dirname(__DIR__,2).'/bootstrap.php';
set_exception_handler(static function(\Throwable $error):void{fwrite(STDERR,$error::class.': '.$error->getMessage()."\n");exit(1);});
(new \OCA\Mcp\Tests\Integration\Forms\FormsResultsScene())->seed();
