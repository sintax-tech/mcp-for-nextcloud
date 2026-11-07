<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Isolated real native service context probe; session changes are test-only, never a product impersonation mechanism. */
use OCA\Forms\Db\Form;
use OCA\Forms\Service\FormsService;
use OCA\Mcp\Modules\Forms\Integration\FormsIdentityProbe;
use OCA\Mcp\Tests\Integration\Forms\FormsScene;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

require dirname(__DIR__,2).'/bootstrap.php';

// Make fixture failures visible even when the core CLI exception handler returns status zero.
set_exception_handler(static function (\Throwable $error): void {
	fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
	exit(1);
});
Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
$scenario = $argv[1];
$session = Server::get(IUserSession::class); $users = Server::get(IUserManager::class);
$session->setUser($scenario === 'other' ? $users->get(FormsScene::OWNER) : ($scenario === 'null' || $scenario === 'lazy' ? null : $users->get(FormsScene::READER)));
$lazy = $scenario === 'lazy' && PHP_VERSION_ID >= 80400;
if ($lazy) { \OC\AppFramework\Utility\SimpleContainer::$useLazyObjects = true; }
$service = Server::get(FormsService::class);
$uninitialized = $lazy && (new \ReflectionClass($service))->isUninitializedLazyObject($service);
if ($lazy && !$uninitialized) { throw new RuntimeException('Native DI did not return a lazy object'); }
if (in_array($scenario, ['null','other','warm'], true)) {
	$transient = new Form();$transient->setId(0);$transient->setOwnerId($scenario === 'other' ? FormsScene::OWNER : FormsScene::READER);$transient->setAccess(['permitAllUsers'=>false,'showToAllUsers'=>false]);
	$service->getPermissions($transient); // Force capture before the deliberate test-only session transition.
}
$sentinelEmpty = Server::get(\OCA\Forms\Db\ShareMapper::class)->findByForm(0) === [];
if (!$sentinelEmpty) { throw new RuntimeException('Third-actor test premise violated: sentinel shares exist'); }
$session->setUser($users->get(FormsScene::READER));
$resolved = Server::get(FormsService::class);
if ($resolved !== $service) { throw new RuntimeException('Expected cached native service identity'); }
$accepted = true;
try { (new FormsIdentityProbe($session))->assertActor($resolved,FormsScene::READER); }
catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure) { $accepted=false; }
$expected = !in_array($scenario,['null','other'],true);
if ($accepted !== $expected) { throw new RuntimeException('Real captured context outcome differs'); }
if ($accepted) { (new FormsIdentityProbe($session))->assertActor($resolved,FormsScene::READER); }
echo json_encode(['scenario'=>$scenario,'accepted'=>$accepted,'sameNativeInstance'=>true,'sentinelWithoutShares'=>$sentinelEmpty,'lazyEnabled'=>$lazy,'initiallyUninitialized'=>$uninitialized],JSON_THROW_ON_ERROR)."\n";
