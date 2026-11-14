<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Notifications;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Notifications\Integration\{NotificationsCapabilities, NotificationsGateway, NotificationsSurface};
use OCA\Mcp\Modules\Notifications\Reading\ListNotificationsHandler;
use OCA\Mcp\Modules\Notifications\Reading\NotificationsInput;
use OCA\Mcp\Modules\Notifications\Tools\ListNotifications;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\{IExpressionBuilder, IQueryBuilder};
use OCP\IDBConnection;
use OCP\Notification\IManager;
use OCP\RichObjectStrings\{IValidator, IRichTextFormatter};
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/** Execute the real native Handler and Notification for both supported server distributions. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationsNativeTest extends TestCase {
	/** @return iterable<string,array{string}> supported distribution pins */
	public static function pins(): iterable { yield 'NC34' => ['34']; yield 'NC35' => ['35']; }
	/** @param string $major source pin @return void isolated verbatim declarations */
	private function load(string $major): void {
		foreach (['Notification', 'Action', 'Handler'] as $class) require __DIR__.'/../../../fixtures/notifications-native/'.$major.'/'.$class.'.php';
	}
	/** @return \OC\Notification\Notification real in-memory value; no rich rendering is permitted */
	private function notification(): \OC\Notification\Notification {
		$validator=$this->createMock(IValidator::class); $formatter=$this->createMock(IRichTextFormatter::class);
		foreach ([$validator, $formatter] as $service) foreach ((new ReflectionClass($service))->getInterfaceNames() as $interface) {
			if (!str_starts_with($interface, 'OCP\\')) continue;
			foreach ((new ReflectionClass($interface))->getMethods() as $method) $service->expects(self::never())->method($method->getName());
		}
		return new \OC\Notification\Notification($validator, $formatter);
	}
	/** @param int $id own synthetic notification @return array<string,mixed> dangerous payload must remain opaque */
	private static function row(int $id): array {
		return ['notification_id'=>$id,'user'=>'alice','app'=>'files_sharing','timestamp'=>1800000000,
			'object_type'=>'remote_share','object_id'=>'private-object','subject'=>'secret-token','subject_parameters'=>'{"token":"secret-token"}',
			'message'=>'secret-message','message_parameters'=>'["secret-path"]','link'=>'https://secret.invalid/token', 'icon'=>'https://secret.invalid/icon',
			'actions'=>'[{"label":"secret-action","link":"https://secret.invalid/action-token","type":"DELETE","primary":true}]'];
	}
	/** @param list<array<string,mixed>> $rows native query result @param int $limit strict SQL bound @return NotificationsGateway real native path with all effects refused */
	private function gateway(array $rows, int $limit): NotificationsGateway {
		$manager=$this->createMock(IManager::class);
		foreach ((new ReflectionClass(IManager::class))->getMethods() as $method) {
			if ($method->getName() !== 'createNotification') $manager->expects(self::never())->method($method->getName());
		}
		$manager->expects(self::exactly(count($rows)+1))->method('createNotification')->willReturnCallback(fn()=>$this->notification());
		$query=$this->createMock(IQueryBuilder::class);
		foreach (['select','from','orderBy'] as $method) $query->method($method)->willReturnSelf();
		$query->expects(self::once())->method('orderBy')->with('notification_id','DESC')->willReturnSelf();
		$query->expects(self::once())->method('setMaxResults')->with($limit)->willReturnSelf();
		$query->method('createNamedParameter')->willReturnCallback(static fn($v)=>(string)$v);
		$expr=$this->createMock(IExpressionBuilder::class); $expr->method('eq')->willReturnCallback(static fn($c,$v)=>$c.'='.$v);
		$query->method('expr')->willReturn($expr);
		$query->expects(self::once())->method('andWhere')->with('user=alice')->willReturnSelf();
		foreach (['executeStatement','insert','update','delete','set','setValue','values'] as $method) if (method_exists($query,$method)) $query->expects(self::never())->method($method);
		$result=$this->createMock(IResult::class); $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows,false]);
		$result->expects(self::once())->method('closeCursor'); $query->expects(self::once())->method('executeQuery')->willReturn($result);
		$db=$this->createMock(IDBConnection::class);
		foreach ((new ReflectionClass(IDBConnection::class))->getMethods() as $method) if ($method->getName() !== 'getQueryBuilder') $db->expects(self::never())->method($method->getName());
		$db->expects(self::once())->method('getQueryBuilder')->willReturn($query);
		$handler=new \OCA\Notifications\Handler($db,$manager);
		$container=$this->createMock(ContainerInterface::class);
		$container->expects(self::once())->method('get')->with(NotificationsSurface::HANDLER)->willReturn($handler);
		return new NotificationsGateway($container,$manager,new NotificationsSurface());
	}
	/** @param string $major source pin @return void real SQL owner predicate, whitelist, no formatter/notifier/write/push/dismiss */
	#[DataProvider('pins')]
	public function testRealHandlerReturnsOnlyOpaqueOwnedMetadataWithoutEffects(string $major): void {
		$this->load($major);
		$tool=new ListNotifications(new ListNotificationsHandler($this->gateway([self::row(8),self::row(6)],50),new NotificationsInput()));
		$reply=$tool->execute([],new ToolContext('alice'));
		self::assertEmpty($reply['isError']??false);
		$data=json_decode($reply['content'][0]['text'],true,512,JSON_THROW_ON_ERROR);
		self::assertSame([['id'=>8,'app'=>'files_sharing','datetime'=>'2027-01-15T08:00:00+00:00'],['id'=>6,'app'=>'files_sharing','datetime'=>'2027-01-15T08:00:00+00:00']],$data['items']);
		self::assertTrue($data['partial']); self::assertFalse($data['truncated']);
		self::assertSame(['items','partial','coverage','truncated'],array_keys($data));
		self::assertStringNotContainsString('secret',json_encode($data));
	}
	/** @param string $major source pin @return void requested 200 ceiling and exact-limit ambiguity are explicit */
	#[DataProvider('pins')]
	public function testCeilingIsNotCompleteInboxOrOlderPageCursor(string $major): void {
		$this->load($major); $rows=array_map(self::row(...),range(200,1));
		$r=$this->gateway($rows,200)->latest('alice',200);
		self::assertCount(200,$r['items']); self::assertTrue($r['truncated']);
		self::assertSame(range(200,1),array_column($r['items'],'id'));
	}
	/** @param string $major source pin @return void empty and malformed native rows cannot imply complete coverage */
	#[DataProvider('pins')]
	public function testNativeMalformedRowsAreOmittedWithoutRendering(string $major): void {
		$this->load($major); $row=self::row(8); $row['app']='';
		$r=$this->gateway([$row],50)->latest('alice',50); self::assertSame([],$r['items']);
	}
	/** @param string $major source pin @return void even a faulty native owner result fails closed */
	#[DataProvider('pins')]
	public function testForeignRowNeverLeavesTheAdapter(string $major): void {
		$this->load($major); $row=self::row(8); $row['user']='bob';
		$this->expectException(PolicyUnavailable::class); $this->gateway([$row],50)->latest('alice',50);
	}
	/** @param string $major source pin @return void source provenance and real accepted prerelease manifest */
	#[DataProvider('pins')]
	public function testSourcePinsAndLazyCapabilities(string $major): void {
		$this->load($major); $root=__DIR__.'/../../../fixtures/notifications-native/';
		$pins=json_decode(file_get_contents($root.'sources.json'),true)['versions'][$major];
		foreach ($pins as $file=>$pin) self::assertSame($pin['sha256'],hash_file('sha256',$root.$major.'/'.$file));
		$surface=new NotificationsSurface(); self::assertTrue($surface->supported()); self::assertFalse($surface->dismissSupported());
		require $root.$major.'/Push.php'; require $root.$major.'/Exceptions/NotificationNotFoundException.php';
		self::assertTrue((new NotificationsSurface())->dismissSupported());
		foreach (['7.0.0-dev.1'=>true,'7.0.0'=>true,'8.0.0'=>true,'6.9.9'=>false,'9.0.0'=>false,''=>false] as $version=>$expected) {
			$apps=$this->createMock(IAppManager::class); $apps->method('getAppVersion')->willReturn($version); $apps->method('isEnabledForAnyone')->willReturn(true);
			$probe=new NotificationsCapabilities($apps,new NotificationsSurface());
			self::assertSame($expected,$probe->supports('notifications.native')); self::assertSame($expected,$probe->supports('notifications.dismiss')); self::assertFalse($probe->supports('other'));
		}
		$apps=$this->createMock(IAppManager::class); $apps->method('getAppVersion')->willReturn('8.0.0'); $apps->method('isEnabledForAnyone')->willReturn(false);
		self::assertFalse((new NotificationsCapabilities($apps,new NotificationsSurface()))->supports('notifications.native'));
		self::assertFalse((new NotificationsCapabilities($apps,new NotificationsSurface()))->supports('notifications.dismiss'));
		$apps=$this->createMock(IAppManager::class); $apps->method('getAppVersion')->willReturn('8.0.0'); $apps->method('isEnabledForAnyone')->willReturn(true);
		$missing=new NotificationsCapabilities($apps,new NotificationsSurface('MissingHandler'));
		self::assertFalse($missing->supports('notifications.native')); self::assertFalse($missing->supports('notifications.dismiss'));
		$source=file_get_contents($root.$major.'/Manager.php');
		self::assertStringContainsString('$notification = $notifier->prepare($notification, $languageCode);',$source);
		self::assertStringContainsString('$this->markProcessed($notification);',$source);
		self::assertStringContainsString("->where(\$sql->expr()->gt('notification_id'",file_get_contents($root.$major.'/Handler.php'));
	}
	/** @return iterable<string,array{string,string}> unsafe native read signatures */
	public static function shapes(): iterable {
		yield 'missing get' => ['public function get(', 'public function unavailableGet('];
		yield 'protected get' => ['public function get(', 'protected function get('];
		yield 'static get' => ['public function get(', 'public static function get('];
		yield 'wrong bound' => ['int $limit = 25): array', 'string $limit = "25"): array'];
		yield 'reference filter' => ['get(INotification $notification', 'get(INotification &$notification'];
	}
	/** @param string $before pinned text @param string $after incompatible signature @return void capability fails before service construction */
	#[DataProvider('shapes')]
	public function testUnsafeReadShapeFailsClosed(string $before, string $after): void {
		$source=file_get_contents(__DIR__.'/../../../fixtures/notifications-native/34/Handler.php');
		self::assertStringContainsString($before,$source); eval(substr(str_replace($before,$after,$source),5));
		self::assertFalse((new NotificationsSurface())->supported());
	}
	/** @param string $major source pin @return void deterministic fixture generation preserves all unrelated classes */
	#[DataProvider('pins')]
	public function testSurfaceGeneratorPreservesOtherAppsAndIsIdempotent(string $major): void {
		$dir=sys_get_temp_dir().'/mcp-notifications-fixture-'.bin2hex(random_bytes(6)); mkdir($dir);
		$target=$dir.'/'.$major.'.php'; copy(__DIR__.'/../../../fixtures/nextcloud-api/'.$major.'.php',$target);
		try {
			$before=require $target; $bytes=null;
			for ($i=0;$i<2;$i++) {
				$process=proc_open([PHP_BINARY,__DIR__.'/../../../fixtures/nextcloud-api/generate-notifications.php',$major,$dir],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
				self::assertIsResource($process); fclose($pipes[0]);
				$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
				self::assertSame(0,proc_close($process),$output);
				if ($bytes!==null) self::assertSame($bytes,file_get_contents($target));
				$bytes=file_get_contents($target);
			}
			$after=require $target; $class='OCA\\Notifications\\Handler'; self::assertNotEmpty($after['classes'][$class]);
			unset($before['classes'][$class],$after['classes'][$class],$before['classes']['OCP\\Notification\\INotification'],$after['classes']['OCP\\Notification\\INotification']); self::assertSame($before,$after);
		} finally { unlink($target); rmdir($dir); }
	}

}
