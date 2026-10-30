<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Fresh native actor context for comparison, not a product path or a session/DI reset. */
use OCA\Forms\Service\FormsService;
use OCA\Forms\Db\FormMapper;
use OCA\Mcp\Tests\Integration\Forms\FormsApiScene;
use OCP\Server;

require dirname(__DIR__,2).'/bootstrap.php';

// Make fixture failures visible even when the core CLI exception handler returns status zero.
set_exception_handler(static function (\Throwable $error): void {
	fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
	exit(1);
});
$uid=$argv[1];Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
$native=Server::get(FormsService::class);$user=Server::get(\OCP\IUserManager::class)->get($uid);
$owned=(new FormsApiScene())->api($uid,'GET','forms?type=owned');
$own = Server::get(FormMapper::class)->findAllByOwnerId($uid);
$ownIds = array_map(static fn ($form): int => (int)$form->getId(), $own);
$ocsIds = array_column($owned, 'id');
sort($ownIds); sort($ocsIds);
if ($ownIds !== $ocsIds) { throw new RuntimeException('Native owned mapper and OCS catalog differ'); }
$forms=array_merge($own,$native->getSharedForms($user,false));
$ids=[];foreach($forms as $form){if($native->hasUserAccess($form)){$ids[]=(int)$form->getId();}}
$ids=array_values(array_unique($ids));sort($ids);
echo json_encode(['ids'=>$ids,'ocsOwned'=>array_column($owned,'id')],JSON_THROW_ON_ERROR)."\n";
