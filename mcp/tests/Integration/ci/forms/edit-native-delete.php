<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Fixture-only second process: one native logical deletion of a disposable question, never a product tool. */
require dirname(__DIR__,2).'/bootstrap.php';
set_exception_handler(static function(\Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage()."\n");exit(1);});
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
\OCP\Server::get(\OCP\IUserSession::class)->setUser(\OCP\Server::get(\OCP\IUserManager::class)->get($argv[1]));
$c=\OCP\Server::get(\OCA\Mcp\Modules\Forms\Integration\EditNativeFactory::class)->open($argv[1]);
$r=$c->controller->deleteQuestion((int)$argv[2],(int)$argv[3]);
if($r->getStatus()!==200){throw new RuntimeException('Native deletion refused');}
echo 'native-logical-delete';
