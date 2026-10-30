<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Isolates native session capture and explicit fixture failures from HTTP test processes. */
require dirname(__DIR__,2).'/bootstrap.php';
set_exception_handler(static function(\Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage()."\n");exit(1);});
echo json_encode((new \OCA\Mcp\Tests\Integration\Forms\Editing\EditNativeProbe())->run($argv[1],(int)$argv[2],(int)$argv[3],(int)($argv[4]??0)),JSON_THROW_ON_ERROR)."\n";
