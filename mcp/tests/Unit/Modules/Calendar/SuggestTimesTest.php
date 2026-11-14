<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability;
use OCA\Mcp\Modules\Calendar\Presentation\AvailabilitySchema;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarGuideNotes;
use OCA\Mcp\Modules\Calendar\Presentation\SuggestionSchema;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityCandidates;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityParticipants;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityQuery;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityWindow;
use OCA\Mcp\Modules\Calendar\Reading\SuggestTimesHandler;
use OCA\Mcp\Modules\Calendar\Reading\SuggestionInput;
use OCA\Mcp\Modules\Calendar\Tools\CheckAvailability;
use OCA\Mcp\Modules\Calendar\Tools\SuggestTimes;
use OCP\App\IAppManager;
use OCP\Calendar\IAvailabilityResult;
use OCP\Calendar\IManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/** Public suggestion pipeline: bounded calls, explicit stateless continuation, unknowns and policy barriers. */
final class SuggestTimesTest extends TestCase {
	/** @var list<int> exact native start timestamps, used to prove no repeated/skipped candidate on continuation */
	private array $asked = [];
	/** @var list<list<string>> local addresses actually submitted, never augmented with an implicit organizer */
	private array $audiences = [];

	/**
	 * @param string $mode native response mode
	 * @param bool $granted sensitive grant
	 * @param bool $enabled calendar app
	 * @param list<string>|null $scope null unrestricted, any array restricted
	 * @param bool $unavailable unreadable policy
	 * @return ToolRegistry real catalog, validation and admission over the native API double
	 */
	private function registry(string $mode = 'free', bool $granted = true, bool $enabled = true, ?array $scope = null, bool $unavailable = false): ToolRegistry {
		$accounts = [];
		foreach (['alice', 'bob', 'carla'] as $id) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($id);
			$user->method('getEMailAddress')->willReturn($id . '@example.invalid');
			$user->method('isEnabled')->willReturn(true);
			$accounts[$id] = $user;
		}
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn ($id) => $accounts[strtolower($id)] ?? null);
		$users->method('getByEmail')->willReturnCallback(static fn ($email) => array_values(array_filter($accounts, static fn ($user) => $user->getEMailAddress() === $email)));
		$users->expects(self::never())->method('search');
		$manager = $this->createMock(IManager::class);
		// Any read with maintenance or write API is forbidden, including methods added by a future SDK.
		foreach ((new \ReflectionClass(IManager::class))->getMethods() as $method) {
			if ($method->getName() !== 'checkAvailability') $manager->expects(self::never())->method($method->getName());
		}
		$manager->method('checkAvailability')->willReturnCallback(function ($start, $end, $actor, $emails) use ($mode): array {
			$this->asked[] = $start->getTimestamp();
			$this->audiences[] = $emails;
			self::assertSame('alice', $actor->getUID());
			if ($mode === 'error') throw new \RuntimeException('secret event/account');
			$results = [];
			foreach ($emails as $email) {
				if ($mode === 'unknown' && $email === 'carla@example.invalid') continue;
				$result = $this->createMock(IAvailabilityResult::class);
				$result->method('getAttendeeEmail')->willReturn($email);
				$result->method('isAvailable')->willReturn($mode !== 'busy' && !($mode === 'onebusy' && $email === 'carla@example.invalid') && !($mode === 'mixed' && count($this->asked) % 2 === 1));
				$results[] = $result;
			}
			return $results;
		});
		$native = $this->createMock(ContainerInterface::class);
		$native->method('get')->with(IManager::class)->willReturn($manager);
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(static fn ($uid, $module, $operations) => $module === 'calendar' && $operations === ['availability'] && $granted);
		$policy->method('scopeItems')->willReturnCallback(static function () use ($scope, $unavailable) {
			if ($unavailable) throw new PolicyUnavailable('private backend detail');
			return $scope;
		});
		$tool = new SuggestTimes(new SuggestTimesHandler(new AvailabilityQuery($policy, new AvailabilityParticipants($users), new CalendarAvailability($native)), new SuggestionInput(new AvailabilityWindow()), new AvailabilityCandidates()));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(SuggestTimes::class)->willReturn($tool);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($enabled);
		return new ToolRegistry([new CalendarModule($container)], $policy, $apps, $users, new NullLogger());
	}

	/** @param ToolRegistry $registry public pipeline @param array<string,mixed> $arguments parameters @return array<string,mixed> decoded MCP result */
	private function call(ToolRegistry $registry, array $arguments): array {
		$result = $registry->call('calendar_suggest_times', $arguments, 'alice');
		self::assertArrayNotHasKey('isError', $result);
		return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
	}

	/** @return void exactly 32 candidates and calls, then a deliberate next page starts at the next untouched slot */
	public function testBudgetAndStatelessContinuationNeverRepeatOrSkipCandidates(): void {
		$registry = $this->registry('busy');
		$first = $this->call($registry, AvailabilityCandidatesTest::args());
		self::assertSame([], $first['suggestions']);
		self::assertSame(32, $first['coverage']['examined']);
		self::assertCount(32, $this->asked);
		self::assertSame(32, $first['next_index']);
		self::assertTrue($first['partial']);
		self::assertTrue($first['truncated']);
		self::assertSame(array_replace(AvailabilityCandidatesTest::args(), ['next_index' => 32]), $first['continuation']);
		// A fresh registry has no approval/cursor state, and must produce the same continuation page.
		$second = $this->call($this->registry('busy'), $first['continuation']);
		self::assertSame(32, $second['coverage']['from_index']);
		self::assertSame(64, $second['next_index']);
		self::assertCount(64, $this->asked);
		foreach ($this->asked as $i => $timestamp) self::assertSame(strtotime('2026-11-02T00:00:00Z') + $i * 900, $timestamp);
	}

	/** @return void five suggestions stop early, and the continuation keeps the next candidate instead of hiding it */
	public function testSuggestionLimitStopsAtFiveAndIntersectsAllBooleans(): void {
		$registry = $this->registry('mixed');
		$args = array_replace(AvailabilityCandidatesTest::args(), ['participants' => ['bob', 'carla']]);
		$first = $this->call($registry, $args);
		self::assertCount(5, $first['suggestions']);
		self::assertSame(10, $first['coverage']['examined']);
		self::assertSame(10, $first['next_index']);
		foreach ($first['suggestions'] as $i => $window) self::assertSame(strtotime($window['start']), $this->asked[$i * 2 + 1]);
		self::assertSame(10, $first['continuation']['next_index']);
	}

	/** @return void one busy participant blocks a candidate even when another is positively free */
	public function testOneBusyParticipantPreventsASuggestion(): void {
		$reply = $this->call($this->registry('onebusy'), array_replace(AvailabilityCandidatesTest::args(), ['participants' => ['bob', 'carla']]));
		self::assertSame([], $reply['suggestions']);
		self::assertSame(0, $reply['coverage']['unknown_windows']);
		self::assertCount(32, $this->asked);
	}

	/** @return void a continuation has no authority and cannot survive a scope or grant change */
	public function testContinuationRechecksScopeAndGrant(): void {
		$first = $this->call($this->registry('busy'), AvailabilityCandidatesTest::args());
		$scoped = $this->registry(scope: [])->call('calendar_suggest_times', $first['continuation'], 'alice');
		self::assertTrue($scoped['isError']);
		self::assertCount(32, $this->asked);
		$this->expectException(InvalidArgumentException::class);
		try { $this->registry(granted: false)->call('calendar_suggest_times', $first['continuation'], 'alice'); }
		finally { self::assertCount(32, $this->asked); }
	}

	/** @return void supplied workdays with no overlap produce zero candidates without guessing other working hours */
	public function testNoMatchingWorkdayDoesNotReadNative(): void {
		$result = $this->call($this->registry(), array_replace(AvailabilityCandidatesTest::args(), ['to' => '2026-11-03T00:00:00Z',
			'working_hours' => ['weekdays' => [3], 'start' => '09:00', 'end' => '17:00']]));
		self::assertSame(0, $result['coverage']['total_candidates']);
		self::assertSame(0, $result['coverage']['examined']);
		self::assertSame([], $result['suggestions']);
		self::assertNull($result['next_index']);
		self::assertSame([], $this->asked);
	}

	/** @return iterable<string,array{string,list<string>}> ways a candidate cannot be proven free */
	public static function unknown(): iterable {
		yield 'one omitted participant' => ['unknown', ['bob', 'carla']];
		yield 'batch failure' => ['error', ['bob', 'carla']];
		yield 'external input' => ['free', ['bob', 'external@example.invalid']];
	}

	/** @param string $mode native mode @param list<string> $participants supplied audience @return void one unknown blocks the whole candidate, without automatic retry */
	#[DataProvider('unknown')]
	public function testUnknownIsNeverSuggested(string $mode, array $participants): void {
		$reply = $this->call($this->registry($mode), array_replace(AvailabilityCandidatesTest::args(), ['participants' => $participants]));
		self::assertSame([], $reply['suggestions']);
		self::assertSame(32, $reply['coverage']['unknown_windows']);
		self::assertSame(32, $reply['next_index']);
		self::assertCount(32, $this->asked);
	}

	/** @return void final chunk does no extra call; index at end is valid, beyond end is refused */
	public function testEndOfCandidatesAndAnEmptyScheduleAreExplicit(): void {
		$registry = $this->registry('busy');
		$tail = $this->call($registry, AvailabilityCandidatesTest::args() + ['next_index' => 670]);
		self::assertSame(2, $tail['coverage']['examined']);
		self::assertNull($tail['next_index']);
		self::assertNull($tail['continuation']);
		self::assertFalse($tail['truncated']);
		self::assertTrue($tail['partial'], 'the tail alone never claims full-horizon coverage');
		$empty = $this->call($registry, AvailabilityCandidatesTest::args() + ['next_index' => 672]);
		self::assertSame(0, $empty['coverage']['examined']);
		self::assertCount(2, $this->asked);
		$this->expectException(InvalidArgumentException::class);
		$registry->call('calendar_suggest_times', AvailabilityCandidatesTest::args() + ['next_index' => 673], 'alice');
	}

	/** @return iterable<string,array{bool,bool,?array,bool}> all admission barriers precede native API */
	public static function barriers(): iterable {
		yield 'grant' => [false, true, null, false];
		yield 'app' => [true, false, null, false];
		yield 'restricted' => [true, true, ['1'], false];
		yield 'empty scope' => [true, true, [], false];
		yield 'unavailable' => [true, true, null, true];
	}

	/** @param bool $grant availability grant @param bool $app app enabled @param array|null $scope collections @param bool $unavailable policy failure @return void cannot consult or suggest through a barrier */
	#[DataProvider('barriers')]
	public function testBarriersRefuseBeforeAnyRead(bool $grant, bool $app, ?array $scope, bool $unavailable): void {
		$registry = $this->registry(granted: $grant, enabled: $app, scope: $scope, unavailable: $unavailable);
		try {
			if (!$grant || !$app) $this->expectException(InvalidArgumentException::class);
			elseif ($unavailable) $this->expectException(PolicyUnavailable::class);
			$result = $registry->call('calendar_suggest_times', AvailabilityCandidatesTest::args(), 'alice');
			self::assertTrue($result['isError']);
		} finally {
			self::assertSame([], $this->asked);
		}
	}

	/** @return void closed schema keeps mandatory work hours, grant and read-only annotations explicit */
	public function testPublicDefinitionIsReadOnlyAndNeverAssumesWorkHours(): void {
		$registry = $this->registry();
		$tool = array_values(array_filter($registry->list('alice'), static fn ($row) => $row['name'] === 'calendar_suggest_times'))[0];
		self::assertTrue($tool['annotations']['readOnlyHint']);
		self::assertFalse($tool['annotations']['destructiveHint']);
		self::assertContains('working_hours', $tool['inputSchema']['required']);
		self::assertFalse($tool['inputSchema']['additionalProperties']);
		self::assertArrayNotHasKey('confirm', (array)$tool['inputSchema']['properties']);
		self::assertSame([], $this->asked, 'listing is lazy');
		$args = AvailabilityCandidatesTest::args(); unset($args['working_hours']);
		$this->expectException(InvalidArgumentException::class);
		$registry->call('calendar_suggest_times', $args, 'alice');
	}

	/** @return void schema refuses extra authority fields, excess participants and invalid budgets before any native call */
	public function testInvalidPublicInputsNeverReadNative(): void {
		$registry = $this->registry();
		foreach ([['confirm' => true], ['organizer' => 'carla'], ['participants' => array_map(strval(...), range(1, 21))],
			['step_minutes' => 10], ['duration_minutes' => 241], ['to' => '2026-11-10T00:00:00Z'], ['next_index' => -1],
			['working_hours' => ['weekdays' => [1], 'start' => '09:00', 'end' => '17:00', 'assume' => true]]] as $patch) {
			try { $registry->call('calendar_suggest_times', array_replace(AvailabilityCandidatesTest::args(), $patch), 'alice'); self::fail('invalid input accepted'); }
			catch (InvalidArgumentException) { self::assertSame([], $this->asked); }
		}
	}

	/** @return void both descriptions and the guide state the exact opt-in rule for the authenticated actor */
	public function testDescriptionsAndGuideExplainTheActorsOwnCalendar(): void {
		$sentence = "The authenticated user's own calendar is included only when their account ID is listed in participants.";
		foreach ([SuggestionSchema::NOTE, AvailabilitySchema::NOTE] as $note) {
			self::assertStringContainsString($sentence, $note);
			self::assertContains($note, CalendarGuideNotes::notes());
		}
		self::assertStringContainsString("actor_included reports whether the authenticated user's own calendar is among the checked participants.", SuggestionSchema::NOTE);
		self::assertSame(SuggestionSchema::NOTE, SuggestTimes::definition()->description);
		self::assertSame(AvailabilitySchema::NOTE, CheckAvailability::definition()->description);
	}

	/** @return void the output distinguishes organizer context from explicit participation without adding the actor */
	public function testActorIncludedReportsOnlyExplicitParticipants(): void {
		foreach ([[['bob'], false], [['alice', 'bob'], true], [['ALICE', 'bob'], true]] as [$participants, $included]) {
			$result = $this->call($this->registry(), array_replace(AvailabilityCandidatesTest::args(), ['participants' => $participants]));
			self::assertSame($included, $result['actor_included']);
			foreach (array_slice($this->audiences, -5) as $emails) self::assertSame(array_map(static fn ($uid) => strtolower($uid) . '@example.invalid', $participants), $emails);
			self::assertCount(5, $result['suggestions']);
		}
	}
}
