<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Activity;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Activity\IManager;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as NotificationManager;
use OCP\Server;

/** Existing NC34/35 CI cells: own metadata feed, native preferences, pagination and unchanged Activity/notification state. */
final class ActivityFeedTest extends IntegrationTestCase {
	/** @var list<IUser> owned disposable accounts */
	private array $actors = [];
	/** Previous MCP global switch, restored after the test. */
	private ?string $service = null;
	/** @return void remove only owned synthetic accounts and restore the switch */
	protected function tearDown(): void {
		foreach ($this->actors as $actor) $actor->delete();
		if ($this->service !== null) {
			$config=Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$this->service);
		}
		parent::tearDown();
	}
	/** @return void real HTTP reads preserve all native rows, notifications and preferences */
	public function testOwnFeedDoesNotWriteOrMarkNotificationsRead(): void {
		self::assertTrue(Server::get(IAppManager::class)->isEnabledForAnyone('activity'), 'Activity must be enabled by the existing cell provision.');
		$uid='mcp-it-activity-'.bin2hex(random_bytes(4));
		$actor=Server::get(IUserManager::class)->createUser($uid,bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class,$actor); $this->actors[]=$actor;
		$config=Server::get(IConfig::class); $this->service=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'');
		$policy=Server::get(GrantPolicy::class); $policy->setGlobalEnabled(true); $policy->setEligible($uid,true); $policy->setConnected($uid,true);
		$client=new McpClient(LoginFactory::basic($uid));
		self::assertFalse($client->tool('activity_list_events')->ok(), 'new sensitive read remains opt-in');
		$policy->setGrant($uid,'activity','read',true);
		$config->setUserValue($uid,'activity','notify_stream_files_favorites','0');
		$manager=Server::get(IManager::class); $data=Server::get(\OCA\Activity\Data::class); $ids=[];
		foreach ([['',0,''],['files',123,'/secret-deleted-file'],['',0,'']] as $i=>[$type,$id,$name]) {
			$event=$manager->generateEvent()->setApp('mcp')->setType('mcp_probe')->setAffectedUser($uid)->setAuthor($uid)
				->setTimestamp(1800000000+$i)->setSubject('Private subject must not appear',[])->setObject($type,$id,$name);
			$ids[]=$data->send($event);
		}
		$notifications=Server::get(NotificationManager::class);
		$notification=$notifications->createNotification()->setApp('activity')->setUser($uid)->setDateTime(new \DateTime())
			->setObject('activity_notification',(string)$ids[1])->setSubject('mcp_probe',[]);
		$notifications->notify($notification);
		$before=$this->snapshot($uid);
		$first=$client->tool('activity_list_events',['app'=>'mcp','limit'=>1]); self::assertTrue($first->ok(),$first->describe());
		self::assertSame((int)$ids[2],$first->json()['items'][0]['id']); self::assertTrue($first->json()['truncated']);
		$second=$client->tool('activity_list_events',$first->json()['continuation']); self::assertTrue($second->ok(),$second->describe());
		self::assertSame((int)$ids[0],$second->json()['items'][0]['id']);
		self::assertSame(['id','app','datetime'],array_keys($second->json()['items'][0]));
		self::assertStringNotContainsString('Private subject',$second->fingerprint()); self::assertStringNotContainsString('secret-deleted-file',$second->fingerprint());
		$period=$client->tool('activity_list_events',['app'=>'mcp','from'=>'2027-01-15T08:00:02Z','to'=>'2027-01-15T08:00:03Z']);
		self::assertTrue($period->ok(),$period->describe()); self::assertSame([(int)$ids[2]],array_column($period->json()['items'],'id'));
		self::assertSame($before,$this->snapshot($uid), 'no activity mutation, notification dismissal, queue write or preference/read-mark update');
	}
	/**
	 * @param string $uid owned actor
	 * @return array<string,array> exact native state, read only for evidence
	 */
	private function snapshot(string $uid): array {
		$out=[];
		foreach (['activity'=>'affecteduser','activity_mq'=>'amq_affecteduser','notifications'=>'user','preferences'=>'userid'] as $table=>$column) {
			$q=Server::get(IDBConnection::class)->getQueryBuilder();
			$q->select('*')->from($table)->where($q->expr()->eq($column,$q->createNamedParameter($uid)));
			$out[$table]=$q->executeQuery()->fetchAllAssociative();
		}
		return $out;
	}
}
