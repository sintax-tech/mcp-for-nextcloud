<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Revokes only the disposable actor's edit grant in an independent process; no native data is touched. */
require dirname(__DIR__,2).'/bootstrap.php';
set_exception_handler(static function(\Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage()."\n");exit(1);});
\OCP\Server::get(\OCA\Mcp\Service\GrantPolicy::class)->setGrant($argv[1],'forms','edit',false);
echo 'revoked';
