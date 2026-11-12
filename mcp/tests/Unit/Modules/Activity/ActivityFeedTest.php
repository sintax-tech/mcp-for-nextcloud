<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Activity;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Activity\ActivityModule;
use OCA\Mcp\Modules\Activity\Integration\ActivityRows;
use OCA\Mcp\Modules\Activity\Presentation\ActivitySchema;
use OCA\Mcp\Modules\Activity\Reading\ActivityInput;
use OCA\Mcp\Modules\Activity\Reading\ListActivityHandler;
use OCA\Mcp\Modules\Activity\Tools\ListActivity;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/** Conservative ACT-1 projection, privacy, bounded scan and deliberate native-ID continuation. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityFeedTest extends TestCase {
	/** @var list<array{string,int,int}> native calls */
	private array $calls = [];
	/**
	 * @param list<array<string,mixed>> $rows actor's native stream
	 * @return ListActivity real tool over an effect-denying native fixture
	 */
	private function tool(array $rows): ListActivity {
		$previous = null;
		$gateway = ActivityNativeFixture::gateway($this, function ($collector, $settings, $actor, $since, $limit, $sort, $filter, $objectType, $objectId, $returnEvents) use ($rows, &$previous): array {
			self::assertInstanceOf(ActivityRows::class, $collector);
			self::assertNotSame($previous, $collector, 'each page gets a fresh collector'); $previous = $collector;
			self::assertSame(['alice', 'desc', 'all', '', 0, false], [$actor, $sort, $filter, $objectType, $objectId, $returnEvents]);
			$this->calls[] = [$actor, $since, $limit];
			$eligible = array_values(array_filter($rows, static fn ($row) => $since === 0 || $row['activity_id'] < $since));
			foreach (array_slice($eligible, 0, $limit) as $row) $collector->addActivity($row);
			return ['data' => $collector->getActivities(), 'has_more' => count($eligible) > $limit];
		});
		return new ListActivity(new ListActivityHandler($gateway, new ActivityInput(), ActivityProjectionFixture::denied($this)));
	}
	/**
	 * @param ListActivity $tool real read
	 * @param array<string,mixed> $args filters
	 * @return array<string,mixed> decoded metadata
	 */
	private function call(ListActivity $tool, array $args = []): array {
		$reply = $tool->execute($args, new ToolContext('alice'));
		self::assertEmpty($reply['isError'] ?? false, json_encode($reply));
		return json_decode($reply['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
	/** @return void only metadata crosses the tool boundary, never raw text or reference parameters */
	public function testMetadataAndConservativeReferenceOmission(): void {
		$rows = [ActivityNativeFixture::row(12)];
		foreach ([['object_type' => 'files'], ['object_id' => 9], ['file' => '/hidden'], ['link' => 'https://secret.invalid'],
			['object_type' => 'deck'], ['object_name' => 'Hidden calendar'], ['subjectparams' => '["secret"]'], ['messageparams' => '{"id":42}'], ['objects' => [4 => 'secret']]] as $i => $patch) $rows[] = ActivityNativeFixture::row(11 - $i, $patch);
		$result = $this->call($this->tool($rows));
		self::assertSame([['id' => 12, 'app' => 'mcp', 'datetime' => '2027-01-15T08:00:00+00:00']], $result['items']);
		self::assertSame(ActivitySchema::LIMITED, $result['coverage']); self::assertTrue($result['partial']);
		self::assertFalse($result['truncated']); self::assertNull($result['continuation']);
		self::assertSame(['items','partial','coverage','truncated','next_since','continuation'], array_keys($result));
		self::assertStringNotContainsString('secret', json_encode($result));
	}
	/** @return void 500 filtered rows stop the scan, and the next cursor advances even with no visible result */
	public function testBudgetAndContinuationAcrossEntirelyFilteredPages(): void {
		$rows = array_map(static fn ($id) => ActivityNativeFixture::row($id, ['object_type' => $id > 1 ? 'files' : '']), range(600, 1));
		$tool = $this->tool($rows); $first = $this->call($tool);
		self::assertSame([], $first['items']); self::assertTrue($first['truncated']); self::assertSame(101, $first['next_since']);
		self::assertCount(10, $this->calls); self::assertSame(500, array_sum(array_column($this->calls, 2)));
		$second = $this->call($tool, $first['continuation']);
		self::assertSame([1], array_column($second['items'], 'id')); self::assertFalse($second['truncated']);
		self::assertSame(['alice', 101, 50], $this->calls[10]);
	}
	/** @return void max 50 public items and a continuation for the unexamined native tail */
	public function testReturnLimitAndNativeSinceId(): void {
		$tool = $this->tool(array_map(ActivityNativeFixture::row(...), range(100, 1)));
		$first = $this->call($tool); self::assertCount(50, $first['items']); self::assertSame(51, $first['next_since']);
		$second = $this->call($tool, $first['continuation']); self::assertCount(50, $second['items']); self::assertFalse($second['truncated']);
		self::assertSame(range(100, 1), [...array_column($first['items'], 'id'), ...array_column($second['items'], 'id')]);
	}
	/** @return void a small output limit still reads bounded native pages, preserving an unexamined tail without skipping it */
	public function testSmallOutputLimitDoesNotShrinkTheNativePageOrSkipItsTail(): void {
		$tool=$this->tool([ActivityNativeFixture::row(3),ActivityNativeFixture::row(2,['object_type'=>'files']),ActivityNativeFixture::row(1)]);
		$first=$this->call($tool,['limit'=>1]); self::assertSame([3],array_column($first['items'],'id'));
		self::assertTrue($first['truncated']); self::assertSame(3,$first['next_since']); self::assertSame([['alice',0,50]],$this->calls);
		$second=$this->call($tool,$first['continuation']); self::assertSame([1],array_column($second['items'],'id')); self::assertFalse($second['truncated']);
	}

	/** @return void period/app filters are exact, inclusive/exclusive and applied after the native owner filter */
	public function testAppAndPeriodPostFiltering(): void {
		$rows = [ActivityNativeFixture::row(4, ['timestamp' => 1800000001]), ActivityNativeFixture::row(3),
			ActivityNativeFixture::row(2, ['app' => 'other']), ActivityNativeFixture::row(1, ['timestamp' => 1799999999])];
		$r = $this->call($this->tool($rows), ['from' => '2027-01-15T09:00:00+01:00', 'to' => '2027-01-15T08:00:01Z', 'app' => 'mcp']);
		self::assertSame([3], array_column($r['items'], 'id')); self::assertFalse($r['truncated']);
	}
	/** @return void validation never resolves native services and tool schema stays strict and read-only */
	public function testInvalidFiltersDoNotReachNative(): void {
		$tool = $this->tool([]);
		foreach ([['from'=>'2027-02-30T00:00:00Z'],['from'=>'2027-01-01'],['from'=>'2027-01-02T00:00:00Z','to'=>'2027-01-01T00:00:00Z'],['app'=>'../files'],['limit'=>51],['since'=>-1]] as $args) self::assertTrue($tool->execute($args, new ToolContext('alice'))['isError']);
		self::assertSame([], $this->calls);
		$d = ListActivity::definition(); self::assertSame('activity', $d->module); self::assertSame('read', $d->operation); self::assertSame('activity', $d->app);
		self::assertSame(['activity.native'], $d->requires); self::assertFalse($d->inputSchema['additionalProperties']);
		self::assertSame([ActivitySchema::NOTE], (new ActivityModule($this->createMock(ContainerInterface::class)))->guideNotes());
	}
	/** @return void native owner rejection is controlled, while unavailable pagination propagates rather than widening access */
	public function testNativeOwnerFailureAndStalledCursor(): void {
		$native=ActivityNativeFixture::gateway($this,static fn()=>throw new \OutOfBoundsException('other owner details'));
		$r=(new ListActivityHandler($native,new ActivityInput(),ActivityProjectionFixture::denied($this)))->handle('alice',['since'=>123]);
		self::assertTrue($r['isError']); self::assertStringNotContainsString('other owner details',json_encode($r));
		$stalled=ActivityNativeFixture::gateway($this,static fn()=>['data'=>[],'has_more'=>true]);
		$this->expectException(\OCA\Mcp\Access\Policy\PolicyUnavailable::class);
		(new ListActivityHandler($stalled,new ActivityInput(),ActivityProjectionFixture::denied($this)))->handle('alice',[]);
	}

	/** @return void the default-denied grant, app and capability all gate before constructing the lazy read */
	public function testRegistryAdmissionIsOptInAndLazy(): void {
		foreach ([[false,true,true],[true,false,true],[true,true,false],[true,true,true]] as [$grant,$enabled,$capable]) {
			$container=$this->createMock(ContainerInterface::class); $container->expects(self::never())->method('get');
			$policy=$this->createMock(GrantPolicy::class); $policy->method('grantedAny')->with('alice','activity',['read'])->willReturn($grant);
			$apps=$this->createMock(IAppManager::class); $apps->method('isEnabledForUser')->willReturn($enabled);
			$users=$this->createMock(IUserManager::class); $users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
			$probe=new ActivityDiscoveryProbe($capable);
			$registry=new ToolRegistry([new ActivityModule($container)],$policy,$apps,$users,new NullLogger(),null,new \OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry([$probe],new NullLogger()));
			self::assertCount($grant && $enabled && $capable ? 1:0,array_filter($registry->list('alice'), static fn ($row) => $row['name'] === 'activity_list_events'));
			if (!$grant || !$enabled || !$capable) {
				try { $registry->call('activity_list_events', [], 'alice'); self::fail('Unavailable read was admitted'); }
				catch (\InvalidArgumentException) { self::assertSame([], $this->calls); }
			}
		}
		self::assertContains('activity',GrantPolicy::DENIED_READS);
	}
}
