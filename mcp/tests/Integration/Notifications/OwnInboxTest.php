<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Notifications;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
use OCA\Notifications\Handler;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Server;

/** Existing NC34/35 cells: own-only opaque HTTP reads preserve both owners' notification state. */
final class OwnInboxTest extends IntegrationTestCase {
	/** @var list<IUser> disposable test actors */
	private array $actors=[];
	/** Saved service switch, restored even on failure. */
	private ?string $service=null;
	/** @return void remove only owned synthetic accounts and restore MCP's switch */
	protected function tearDown(): void {
		foreach ($this->actors as $actor) $actor->delete();
		if ($this->service!==null) {
			$config=Server::get(IConfig::class);
			$this->service==='' ? $config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$this->service);
		}
		parent::tearDown();
	}
	/** @return void owner predicate, default-deny, bound and opaque payload are proved through the real endpoint */
	public function testOwnInboxReadChangesNeitherOwnerState(): void {
		self::assertTrue(Server::get(IAppManager::class)->isEnabledForAnyone('notifications'));
		foreach (['alice','bob'] as $role) {
			$uid='mcp-it-nt-'.$role.'-'.bin2hex(random_bytes(4));
			$user=Server::get(IUserManager::class)->createUser($uid,bin2hex(random_bytes(24)));
			self::assertInstanceOf(IUser::class,$user); $this->actors[]=$user;
		}
		[$actor,$other]=array_map(static fn(IUser $u)=>$u->getUID(),$this->actors);
		$config=Server::get(IConfig::class); $this->service=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'');
		$policy=Server::get(GrantPolicy::class); $policy->setGlobalEnabled(true); $policy->setEligible($actor,true); $policy->setConnected($actor,true);
		$handler=Server::get(Handler::class); $manager=Server::get(IManager::class); $own=[];
		foreach ([$actor,$other,$actor] as $i=>$owner) {
			$n=$manager->createNotification()->setUser($owner)->setApp('mcp')->setDateTime(new \DateTime())
				->setObject('mcp_probe','private-object')->setSubject('secret-subject',['token'=>'secret-token'])
				->setMessage('secret-message',['path'=>'secret-path'])->setLink('https://secret.invalid/token');
			$n->addAction($n->createAction()->setLabel('secret-action')->setLink('https://secret.invalid/action','DELETE'));
			$id=$handler->add($n); if ($owner===$actor) $own[]=$id;
		}
		$client=new McpClient(LoginFactory::basic($actor));
		self::assertFalse($client->tool('notifications_list')->ok());
		$policy->setGrant($actor,'notifications','read',true);
		$before=$this->snapshot($actor); $otherBefore=$this->snapshot($other);
		$all=$client->tool('notifications_list'); self::assertTrue($all->ok(),$all->describe());
		self::assertSame(array_reverse($own),array_column($all->json()['items'],'id'));
		foreach ($all->json()['items'] as $item) self::assertSame(['id','app','datetime'],array_keys($item));
		self::assertStringNotContainsString('secret',$all->fingerprint()); self::assertTrue($all->json()['partial']); self::assertFalse($all->json()['truncated']);
		$one=$client->tool('notifications_list',['limit'=>1]); self::assertTrue($one->ok(),$one->describe());
		self::assertSame([$own[1]],array_column($one->json()['items'],'id')); self::assertTrue($one->json()['truncated']);
		self::assertFalse($client->tool('notifications_list',['since'=>$own[0]])->ok());
		self::assertSame($before,$this->snapshot($actor),'no dismissal, read-marker or preference maintenance');
		self::assertSame($otherBefore,$this->snapshot($other),'foreign owner state unchanged');
	}

	/** @return void a preview removes nothing; a confirmed dismiss removes only that owner's row; foreign and missing ids match */
	public function testDismissPreviewKeepsBothInboxesAndConfirmationRemovesOnlyTheOwnedId(): void {
		self::assertTrue(Server::get(IAppManager::class)->isEnabledForAnyone('notifications'));
		foreach (['alice', 'bob'] as $role) {
			$uid = 'mcp-it-nt2-' . $role . '-' . bin2hex(random_bytes(4));
			$user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
			self::assertInstanceOf(IUser::class, $user);
			$this->actors[] = $user;
		}
		[$actor, $other] = array_map(static fn (IUser $user): string => $user->getUID(), $this->actors);
		$config = Server::get(IConfig::class);
		$this->service = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible($actor, true);
		$policy->setConnected($actor, true);
		$policy->setGrant($actor, 'notifications', 'dismiss', true);
		$handler = Server::get(Handler::class);
		$manager = Server::get(IManager::class);
		$own = $this->notificationId($handler, $manager, $actor);
		$foreign = $this->notificationId($handler, $manager, $other);
		$client = new McpClient(LoginFactory::basic($actor));
		// The first authenticated request is Nextcloud's first login: it writes lastLogin, firstLogin and app setup
		// preferences of its own. Taken before the baseline, so the baseline holds only what the tool may change.
		self::assertSame(200, $client->listStatus());
		$before = $this->snapshot($actor);
		$otherBefore = $this->snapshot($other);
		$ownedPlan = $client->tool('notifications_dismiss', ['id' => $own]);
		self::assertTrue($ownedPlan->ok(), $ownedPlan->describe());
		self::assertTrue($ownedPlan->structured()['requiresConfirmation'] ?? false);
		self::assertTrue($ownedPlan->structured()['found'] ?? false);
		self::assertStringNotContainsString('secret', $ownedPlan->fingerprint());
		$foreignPlan = $client->tool('notifications_dismiss', ['id' => $foreign]);
		$missingPlan = $client->tool('notifications_dismiss', ['id' => 2147483000]);
		self::assertTrue($foreignPlan->ok(), $foreignPlan->describe());
		self::assertTrue($missingPlan->ok(), $missingPlan->describe());
		$foreignBody = $foreignPlan->structured();
		$missingBody = $missingPlan->structured();
		unset($foreignBody['id'], $missingBody['id']);
		self::assertSame($missingBody, $foreignBody);
		self::assertFalse($foreignBody['found'] ?? true);
		self::assertSame($before, $this->snapshot($actor), 'preview does not dismiss');
		self::assertSame($otherBefore, $this->snapshot($other), 'preview does not touch the other owner');
		$done = $client->write('notifications_dismiss', ['id' => $own]);
		self::assertTrue($done->ok(), $done->describe());
		$receipt = $done->json();
		self::assertSame('dismissed', $receipt['outcome']);
		self::assertSame('sent', $receipt['push']);
		self::assertFalse($receipt['partial']);
		self::assertFalse($receipt['still_present']);
		self::assertStringNotContainsString('secret', $done->fingerprint());
		$left = array_map('strval', array_column($this->snapshot($actor)['notifications'], 'notification_id'));
		self::assertNotContains((string)$own, $left);
		self::assertSame($otherBefore, $this->snapshot($other));
		$foreignReceipt = $client->write('notifications_dismiss', ['id' => $foreign])->json();
		$missingReceipt = $client->write('notifications_dismiss', ['id' => 2147483000])->json();
		unset($foreignReceipt['id'], $missingReceipt['id']);
		self::assertSame($missingReceipt, $foreignReceipt);
		self::assertSame('absent', $foreignReceipt['outcome']);
		self::assertFalse($foreignReceipt['partial']);
		self::assertSame($otherBefore, $this->snapshot($other), 'the other owner row is still there');
	}

	/** @param Handler $handler native storage @param IManager $manager native factory @param string $owner row owner @return int stored id */
	private function notificationId(Handler $handler, IManager $manager, string $owner): int {
		$notification = $manager->createNotification()->setUser($owner)->setApp('mcp')->setDateTime(new \DateTime())
			->setObject('mcp_probe', 'private-object')->setSubject('secret-subject', ['token' => 'secret-token'])
			->setMessage('secret-message', ['path' => 'secret-path'])->setLink('https://secret.invalid/token');
		$notification->addAction($notification->createAction()->setLabel('secret-action')->setLink('https://secret.invalid/action', 'DELETE'));
		return $handler->add($notification);
	}
	/** @param string $uid synthetic actor @return array<string,array> exact notification/preferences state, read only */
	private function snapshot(string $uid): array {
		$out=[];
		foreach (['notifications'=>'user','preferences'=>'userid'] as $table=>$column) {
			$q=Server::get(IDBConnection::class)->getQueryBuilder();
			$q->select('*')->from($table)->where($q->expr()->eq($column,$q->createNamedParameter($uid)));
			$out[$table]=$q->executeQuery()->fetchAllAssociative();
		}
		return $out;
	}
}
