<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Activity;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Activity\Integration\ActivityCapabilities;
use OCA\Mcp\Modules\Activity\Integration\ActivityGateway;
use OCA\Mcp\Modules\Activity\Integration\ActivityRows;
use OCA\Mcp\Modules\Activity\Integration\ActivitySurface;
use OCP\Activity\Exceptions\FilterNotFoundException;
use OCP\Activity\IManager;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

/** Real Data::get and inherited collector contracts from both supported Activity sources, without native effects. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityNativeTest extends TestCase {
	/** @return iterable<string,array{string}> the only supported source pins */
	public static function pins(): iterable { yield 'NC34 Activity7' => ['34']; yield 'NC35 Activity8' => ['35']; }
	/**
	 * @param string $major pinned version
	 * @return void source identity and lazy signature checks
	 */
	#[DataProvider('pins')]
	public function testPinnedSourcesAndCapabilityRange(string $major): void {
		ActivityNativeFixture::load($major);
		$surface = new ActivitySurface(); self::assertTrue($surface->supported());
		$manifest = json_decode(file_get_contents(__DIR__.'/../../../fixtures/activity-native/sources.json'),true)['versions'][$major];
		foreach ($manifest as $file => $source) self::assertSame($source['sha256'], hash_file('sha256',__DIR__.'/../../../fixtures/activity-native/'.$major.'/'.$file));
		foreach (['7.0.0'=>true,'8.0.0'=>true,'6.9.9'=>false,'9.0.0'=>false,''=>false] as $version=>$expected) {
			$apps=$this->createMock(IAppManager::class); $apps->method('getAppVersion')->willReturn($version); $apps->method('isEnabledForAnyone')->willReturn(true);
			$probe=new ActivityCapabilities($apps,$surface); self::assertSame($expected,$probe->supports('activity.native')); self::assertFalse($probe->supports('other'));
		}
		$apps=$this->createMock(IAppManager::class); $apps->method('getAppVersion')->willReturn('8.0.0'); $apps->method('isEnabledForAnyone')->willReturn(false);
		self::assertFalse((new ActivityCapabilities($apps,$surface))->supports('activity.native'));
	}
	/**
	 * @param string $major pinned version
	 * @return void actual native get only selects and consumes settings, not formatters or read markers
	 */
	#[DataProvider('pins')]
	public function testRealDataReadHasNoWriteProviderOrNotifier(string $major): void {
		ActivityNativeFixture::load($major);
		$manager=$this->createMock(IManager::class);
		foreach ((new ReflectionClass(IManager::class))->getMethods() as $method) {
			if (!in_array($method->getName(), ['getFilterById', 'getProviders'], true)) $manager->expects(self::never())->method($method->getName());
		}
		$provider=$this->createMock(\OCP\Activity\IProvider::class); $provider->expects(self::never())->method('parse');
		$manager->expects(self::never())->method('getProviders')->willReturn([$provider]);
		$notifications=$this->createMock(\OCP\Notification\IManager::class); $notifications->expects(self::never())->method('markProcessed');
		$comments=$this->createMock(\OCP\Comments\ICommentsManager::class); $comments->expects(self::never())->method('setReadMark');
		$manager->method('getFilterById')->with('all')->willThrowException(new FilterNotFoundException('all'));
		$config=$this->createMock(IConfig::class);
		foreach ((new ReflectionClass(IConfig::class))->getMethods() as $method) $config->expects(self::never())->method($method->getName());
		// Native methods with effects (including markProcessed and setReadMark) cannot be resolved at all.
		$container=$this->createMock(ContainerInterface::class);
		$settings=$this->getMockBuilder(ActivitySurface::SETTINGS)->disableOriginalConstructor()->getMock();
		$settings->expects(self::once())->method('getUserSetting')->with('alice','stream','files_favorites')->willReturn(false);
		foreach ((new ReflectionClass(ActivitySurface::SETTINGS))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if (!in_array($method->getName(),['__construct','getUserSetting'],true)) $settings->expects(self::never())->method($method->getName());
		}
		$rows=[ActivityNativeFixture::row(7),ActivityNativeFixture::row(6),ActivityNativeFixture::row(5)];
		$query=$this->query($rows,3);
		$query->expects(self::once())->method('where')->with('affecteduser=alice')->willReturnSelf();
		$offset=$this->query([['affecteduser'=>'alice','timestamp'=>1800000000]]);
		$db=$this->createMock(IDBConnection::class); $db->expects(self::exactly(2))->method('getQueryBuilder')->willReturnOnConsecutiveCalls($query,$offset);
		foreach ((new ReflectionClass(IDBConnection::class))->getMethods() as $method) {
			if ($method->getName() !== 'getQueryBuilder') $db->expects(self::never())->method($method->getName());
		}
		$dataClass=ActivitySurface::DATA; $args=[$manager,$db,new NullLogger(),$config];
		if ((new ReflectionClass($dataClass))->getConstructor()->getNumberOfParameters() === 5) {
			$root=$this->createMock(\OCP\Files\IRootFolder::class);
			foreach ((new ReflectionClass(\OCP\Files\IRootFolder::class))->getMethods() as $method) $root->expects(self::never())->method($method->getName());
			$args[]=$root;
		}
		$data=new $dataClass(...$args);
		$container->expects(self::exactly(2))->method('get')->willReturnCallback(static fn($id)=>match($id){ActivitySurface::DATA=>$data,ActivitySurface::SETTINGS=>$settings,\OCP\Notification\IManager::class=>$notifications,\OCP\Comments\ICommentsManager::class=>$comments,default=>throw new \LogicException('Notifier or other service requested')});
		$result=(new ActivityGateway($container,new ActivitySurface()))->page('alice',8,2);
		self::assertSame([7,6],array_column($result['data'],'activity_id')); self::assertTrue($result['has_more']);
		self::assertSame($rows[0],$result['data'][0], 'collector never parses even private source fields');
	}
	/**
	 * @param list<array<string,mixed>> $rows simulated SELECT result
	 * @param int|null $max native LIMIT
	 * @return IQueryBuilder a strictly read-only builder
	 */
	private function query(array $rows, ?int $max=null): IQueryBuilder {
		$query=$this->createMock(IQueryBuilder::class);
		foreach (['select','from','where','andWhere','orderBy','addOrderBy'] as $method) $query->method($method)->willReturnSelf();
		foreach (['executeStatement','insert','update','delete','set','values','setValue'] as $method) if(method_exists($query,$method)) $query->expects(self::never())->method($method);
		if($max!==null) $query->expects(self::once())->method('setMaxResults')->with($max)->willReturnSelf();
		$query->method('createNamedParameter')->willReturnCallback(static fn($value)=>is_string($value)?$value:(string)$value);
		$expr=$this->createMock(IExpressionBuilder::class); $expr->method('eq')->willReturnCallback(static fn($column,$value)=>$column.'='.$value);
		$query->method('expr')->willReturn($expr);
		$result=$this->createMock(IResult::class); $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows,false]);
		$query->expects(self::once())->method('executeQuery')->willReturn($result);
		return $query;
	}
	/** @param string $major pinned version @return void source regeneration is idempotent and preserves every unrelated app surface */
	#[DataProvider('pins')]
	public function testSurfaceGeneratorIsIdempotentAndPreservesOtherApps(string $major): void {
		$dir=sys_get_temp_dir().'/mcp-activity-fixture-'.bin2hex(random_bytes(6)); mkdir($dir);
		$fixture=__DIR__.'/../../../fixtures/nextcloud-api/'.$major.'.php'; $target=$dir.'/'.$major.'.php'; copy($fixture,$target);
		try {
			$before=require $target; $bytes=null;
			for($i=0;$i<2;$i++) {
				$process=proc_open([PHP_BINARY,__DIR__.'/../../../fixtures/nextcloud-api/generate-activity.php',$major,$dir], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
				self::assertIsResource($process); fclose($pipes[0]);
				$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
				self::assertSame(0,proc_close($process),$output);
				if($bytes!==null) self::assertSame($bytes,file_get_contents($target));
				$bytes=file_get_contents($target);
			}
			$after=require $target;
			foreach(['Data','GroupHelper','UserSettings'] as $name) {
				$class='OCA\\Activity\\'.$name; self::assertNotEmpty($after['classes'][$class]);
				unset($before['classes'][$class],$after['classes'][$class]);
			}
			self::assertSame($before,$after);
		} finally { unlink($target); rmdir($dir); }
	}

	/** @return iterable<string,array{string,string}> incompatible collector overrides must never reach PHP inheritance or native services */
	public static function shapes(): iterable {
		yield 'inherited collector property collision' => ['class GroupHelper {', 'class GroupHelper { public array $rows = [];'];
		yield 'final class' => ['class GroupHelper {', 'final class GroupHelper {'];
		yield 'final constructor' => ['public function __construct(', 'final public function __construct('];
		yield 'missing collector entry' => ['function addActivity(', 'function otherActivity('];
		yield 'reference argument' => ['array $activity): void', 'array &$activity): void'];
		yield 'wrong argument type' => ['array $activity): void', 'string $activity): void'];
		yield 'protected collector' => ['public function addActivity(', 'protected function addActivity('];
		yield 'final collector' => ['public function addActivity(', 'final public function addActivity('];
		yield 'reference return' => ['function getActivities()', 'function &getActivities()'];
	}
	/**
	 * @param string $before source member
	 * @param string $after incompatible member
	 * @return void no fallback collector is ever constructed
	 */
	#[DataProvider('shapes')]
	public function testUnsafeCollectorShapeRefusesBeforeResolvingServices(string $before, string $after): void {
		$source=file_get_contents(__DIR__.'/../../../fixtures/activity-native/34/GroupHelper.php');
		self::assertStringContainsString($before,$source);
		eval(substr(str_replace($before,$after,$source),5));
		foreach (['UserSettings','Data'] as $class) require __DIR__.'/../../../fixtures/activity-native/34/'.$class.'.php';
		$surface=new ActivitySurface(); self::assertFalse($surface->supported());
		$container=$this->createMock(ContainerInterface::class); $container->expects(self::never())->method('get');
		$this->expectException(PolicyUnavailable::class); (new ActivityGateway($container,$surface))->page('alice',0,50);
	}

	/** @return void missing or incompatible native surfaces refuse before any service is constructed, with no fallback */
	public function testShapeFailureNeverResolvesNativeServices(): void {
		$container=$this->createMock(ContainerInterface::class); $container->expects(self::never())->method('get');
		$surface=new ActivitySurface(data:'MissingActivityClass'); self::assertFalse($surface->supported());
		$this->expectException(PolicyUnavailable::class); (new ActivityGateway($container,$surface))->page('alice',0,50);
	}
}
