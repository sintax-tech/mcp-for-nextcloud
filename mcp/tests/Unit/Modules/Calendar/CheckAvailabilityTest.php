<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityParticipants;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityQuery;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityWindow;
use OCA\Mcp\Modules\Calendar\Reading\CheckAvailabilityHandler;
use OCA\Mcp\Modules\Calendar\Tools\CheckAvailability;
use OCP\App\IAppManager;
use OCP\Calendar\IAvailabilityResult;
use OCP\Calendar\IManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/** Public pipeline, native request boundary, unknown semantics, budget and offset handling. */
final class CheckAvailabilityTest extends TestCase {
	/** @return array<string, mixed> one offset-defined window */
	private static function args(): array {
		return ['participants' => ['bob'], 'start' => '2026-11-11T12:00:00Z', 'end' => '2026-11-11T13:00:00Z', 'timezone' => 'America/Sao_Paulo'];
	}

	/**
	 * @param callable|null $check native result producer
	 * @param bool $granted availability grant
	 * @param bool $enabled Calendar app gate
	 * @param list<string>|null $scope null unrestricted, [] deny every collection
	 * @param bool $unavailable policy read fails
	 * @return array{ToolRegistry, ContainerInterface} actual tool registry and lazy native container
	 */
	private function registry(?callable $check = null, bool $granted = true, bool $enabled = true, ?array $scope = null, bool $unavailable = false): array {
		$accounts = [];
		foreach (['alice', 'bob', 'carla', 'noemail', 'disabled', 'ambiguous', 'alias'] as $id) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($id);
			$user->method('isEnabled')->willReturn($id !== 'disabled');
			$email = $id === 'noemail' ? null : ($id === 'alias' ? 'ambiguous' : $id) . '@example.invalid';
			$user->method('getEMailAddress')->willReturn($email);
			$accounts[$id] = $user;
		}
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn ($id) => $accounts[$id] ?? null);
		$users->method('getByEmail')->willReturnCallback(static fn ($email) => array_values(array_filter($accounts, static fn ($user) => $user->getEMailAddress() === $email)));
		$users->expects(self::never())->method('search');
		$manager = $this->createMock(IManager::class);
		$manager->method('checkAvailability')->willReturnCallback($check ?? fn () => [$this->verdict('bob@example.invalid', true)]);
		$native = $this->createMock(ContainerInterface::class);
		$native->method('get')->with(IManager::class)->willReturn($manager);
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(static fn ($uid, $module, $operations) => $module === 'calendar' && $operations === ['availability'] && $granted);
		$policy->method('scopeItems')->with('alice', 'calendar')->willReturnCallback(static function () use ($scope, $unavailable) {
			if ($unavailable) throw new PolicyUnavailable('private backend detail');
			return $scope;
		});
		$tool = new CheckAvailability(new CheckAvailabilityHandler(new AvailabilityQuery($policy, new AvailabilityParticipants($users), new CalendarAvailability($native)), new AvailabilityWindow()));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(CheckAvailability::class)->willReturn($tool);
		$module = new CalendarModule($container);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($enabled);
		return [new ToolRegistry([$module], $policy, $apps, $users, new NullLogger()), $native];
	}

	/**
	 * @param string $email native address
	 * @param bool $available native boolean
	 * @return IAvailabilityResult the actual public API shape
	 */
	private function verdict(string $email, bool $available): IAvailabilityResult {
		$result = $this->createMock(IAvailabilityResult::class);
		$result->method('getAttendeeEmail')->willReturn($email);
		$result->method('isAvailable')->willReturn($available);
		return $result;
	}

	/** @return void exact actor, one request, no content or resolved account attributes returned */
	public function testOneWindowUsesAuthenticatedOrganizerAndProjectsOnlyStatuses(): void {
		$calls = 0;
		[$registry] = $this->registry(function ($start, $end, $organizer, $emails) use (&$calls) {
			$calls++;
			self::assertSame('alice', $organizer->getUID());
			self::assertSame(['bob@example.invalid', 'carla@example.invalid'], $emails);
			self::assertSame('2026-11-11T12:00:00+00:00', $start->format(DATE_ATOM));
			self::assertSame(3600, $end->getTimestamp() - $start->getTimestamp());
			return [$this->verdict('mailto:BOB@example.invalid', true), $this->verdict('carla@example.invalid', false), $this->verdict('ignored@example.invalid', true)];
		});
		$result = $registry->call('calendar_check_availability', array_replace(self::args(), ['participants' => ['bob', 'carla']]), 'alice');
		$data = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
		self::assertSame(1, $calls);
		self::assertSame(['start', 'end', 'timezone', 'participants', 'complete'], array_keys($data));
		self::assertSame('2026-11-11T09:00:00-03:00', $data['start']);
		self::assertSame([['participant' => 'bob', 'status' => 'free'], ['participant' => 'carla', 'status' => 'busy']], $data['participants']);
		self::assertTrue($data['complete']);
	}

	/** @return void no name search, no external address passed to the API, same unknown for all hidden identities */
	public function testExternalMissingDisabledAmbiguousAndEmailLessAreAllUnknown(): void {
		[$registry, $native] = $this->registry();
		$native->expects(self::never())->method('get');
		$ids = ['outside@example.invalid', 'not-a-user', 'disabled', 'ambiguous', 'noemail'];
		$data = json_decode($registry->call('calendar_check_availability', array_replace(self::args(), ['participants' => $ids]), 'alice')['content'][0]['text'], true);
		self::assertSame($ids, array_column($data['participants'], 'participant'));
		self::assertSame(array_fill(0, 5, 'unknown'), array_column($data['participants'], 'status'));
		self::assertFalse($data['complete']);
	}

	/** @return void both duration boundaries and all 20 entries are accepted, with no search horizon */
	public function testInclusiveBudgetsAndDistantWindow(): void {
		foreach (['12:15:00', '16:00:00'] as $end) {
			[$registry, $native] = $this->registry();
			$native->expects(self::never())->method('get');
			$ids = array_map(static fn ($id) => 'unknown-' . $id, range(1, 20));
			$data = json_decode($registry->call('calendar_check_availability', array_replace(self::args(), [
				'participants' => $ids, 'start' => '2030-01-01T12:00:00Z', 'end' => '2030-01-01T' . $end . 'Z',
			]), 'alice')['content'][0]['text'], true);
			self::assertCount(20, $data['participants']);
			self::assertFalse($data['complete']);
		}
	}

	/** @return void the actor may be included as a participant without trusting an organizer argument */
	public function testOrganizerCanCheckTheirOwnAvailability(): void {
		[$registry] = $this->registry(fn ($start, $end, $actor, $emails) => [$this->verdict('alice@example.invalid', false)]);
		$data = json_decode($registry->call('calendar_check_availability', array_replace(self::args(), ['participants' => ['alice']]), 'alice')['content'][0]['text'], true);
		self::assertSame([['participant' => 'alice', 'status' => 'busy']], $data['participants']);
	}

	/** @return void a missing organizer fails with a client-safe argument message */
	public function testMissingOrganizerCannotStartTheNativeRequest(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);
		$this->expectException(CalendarArgumentException::class);
		(new AvailabilityParticipants($users))->organizer('missing');
	}

	/** @return void UI titles and refusals use the existing locales without changing the machine status codes */
	public function testAvailabilityTextsAreTranslatedInEveryExistingLocale(): void {
		foreach (['pt_BR', 'es', 'de'] as $locale) {
			\OCA\Mcp\L10n\Translator::use(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n($locale));
			try {
				[$registry, $native] = $this->registry(scope: []);
				$native->expects(self::never())->method('get');
				$result = $registry->call('calendar_check_availability', self::args(), 'alice');
				self::assertTrue($result['isError']);
				self::assertStringNotContainsString('Availability cannot be checked', $result['content'][0]['text']);
				self::assertNotSame('Check availability', \OCA\Mcp\Mcp\Tools\ToolPresentation::title('calendar_check_availability'));
			} finally {
				\OCA\Mcp\L10n\Translator::reset();
			}
		}
	}

	/** @return iterable<string, array{string}> uncheckable native cases */
	public static function unknownCases(): iterable {
		foreach (['omitted', 'denied', 'conflicting', 'malformed'] as $case) yield $case => [$case];
	}

	/** @param string $case native failure mode @return void missing, ACL denial and malformed results never imply free */
	#[DataProvider('unknownCases')]
	public function testNativeUnknownNeverBecomesFree(string $case): void {
		[$registry] = $this->registry(function () use ($case) {
			return match ($case) {
				'omitted' => [],
				'denied' => throw new \RuntimeException('secret ACL/account/event'),
				'conflicting' => [$this->verdict('bob@example.invalid', true), $this->verdict('bob@example.invalid', false), $this->verdict('bob@example.invalid', true)],
				'malformed' => [new \stdClass()],
			};
		});
		$data = json_decode($registry->call('calendar_check_availability', self::args(), 'alice')['content'][0]['text'], true);
		self::assertSame([['participant' => 'bob', 'status' => 'unknown']], $data['participants']);
		self::assertFalse($data['complete']);
	}

	/** @return iterable<string, array{array<string, mixed>}> invalid inputs rejected without native reads */
	public static function invalidInputs(): iterable {
		yield 'empty people' => [['participants' => []]];
		yield 'too many people' => [['participants' => array_map(strval(...), range(1, 21))]];
		yield 'duplicate people' => [['participants' => ['bob', 'bob']]];
		yield 'external organizer injection' => [['organizer' => 'bob']];
		yield 'missing zone' => [['timezone' => '']];
		yield 'bad zone' => [['timezone' => 'Mars/Olympus']];
		yield 'implicit time' => [['start' => '2026-11-11T12:00:00']];
		yield 'date only' => [['start' => '2026-11-11']];
		yield 'invalid date' => [['start' => '2026-02-30T12:00:00Z']];
		yield 'invalid hour' => [['start' => '2026-11-11T24:00:00Z']];
		yield 'bad offset' => [['start' => '2026-11-11T12:00:00+25:00']];
		yield 'zero duration' => [['end' => '2026-11-11T12:00:00Z']];
		yield 'reversed' => [['end' => '2026-11-11T11:00:00Z']];
		yield 'below minimum' => [['end' => '2026-11-11T12:14:59Z']];
		yield 'over maximum' => [['end' => '2026-11-11T16:00:01Z']];
	}

	/** @param array<string, mixed> $patch invalid patch @return void schema and semantic limits precede native calls */
	#[DataProvider('invalidInputs')]
	public function testInvalidInputsDoNotCallNative(array $patch): void {
		[$registry, $native] = $this->registry();
		$native->expects(self::never())->method('get');
		$this->expectException(InvalidArgumentException::class);
		$registry->call('calendar_check_availability', array_replace(self::args(), $patch), 'alice');
	}

	/** @return void an ambiguous DST wall time is disambiguated by its explicit offset, not silently guessed */
	public function testDstOverlapUsesInstantsAndDisplaysTheChosenZone(): void {
		[$registry] = $this->registry(function ($start, $end) {
			self::assertSame(3600, $end->getTimestamp() - $start->getTimestamp());
			return [$this->verdict('bob@example.invalid', true)];
		});
		$data = json_decode($registry->call('calendar_check_availability', array_replace(self::args(), [
			'start' => '2026-11-01T01:30:00-04:00', 'end' => '2026-11-01T01:30:00-05:00', 'timezone' => 'America/New_York',
		]), 'alice')['content'][0]['text'], true);
		self::assertSame('2026-11-01T01:30:00-04:00', $data['start']);
		self::assertSame('2026-11-01T01:30:00-05:00', $data['end']);
	}

	/** @return iterable<string, array{bool, bool, ?array, bool}> barriers that must not resolve the native manager */
	public static function barriers(): iterable {
		yield 'grant denied' => [false, true, null, false];
		yield 'app absent' => [true, false, null, false];
		yield 'collection restricted' => [true, true, ['1'], false];
		yield 'no collections' => [true, true, [], false];
		yield 'unreadable scope' => [true, true, null, true];
	}

	/** @param bool $granted grant @param bool $enabled app @param array|null $scope collection mode @param bool $unavailable policy failure @return void fail closed, never widening collection access */
	#[DataProvider('barriers')]
	public function testAdmissionAndScopePrecedeAnyNativeCall(bool $granted, bool $enabled, ?array $scope, bool $unavailable): void {
		[$registry, $native] = $this->registry(granted: $granted, enabled: $enabled, scope: $scope, unavailable: $unavailable);
		$native->expects(self::never())->method('get');
		if (!$granted || !$enabled) {
			self::assertNotContains('calendar_check_availability', array_column($registry->list('alice'), 'name'));
			$this->expectException(InvalidArgumentException::class);
		} elseif ($unavailable) {
			$this->expectException(PolicyUnavailable::class);
		}
		$result = $registry->call('calendar_check_availability', self::args(), 'alice');
		self::assertTrue($result['isError']);
		self::assertStringContainsString('cannot preserve', $result['content'][0]['text']);
	}

	/** @return void separate grant and read-only annotations do not depend on calendar.read or trigger confirmation */
	public function testDefinitionGuideAndReadOnlyMetadataUseTheSeparateGrant(): void {
		self::assertFalse(GrantPolicy::defaultGranted('calendar', 'availability'));
		[$registry] = $this->registry();
		$tool = array_values(array_filter($registry->list('alice'), static fn ($tool) => $tool['name'] === 'calendar_check_availability'))[0];
		self::assertTrue($tool['annotations']['readOnlyHint']);
		self::assertFalse($tool['annotations']['destructiveHint']);
		self::assertArrayNotHasKey('confirm', (array)$tool['inputSchema']['properties']);
		self::assertFalse($tool['inputSchema']['additionalProperties']);
		self::assertContains('calendar_check_availability', array_column($registry->definitions('alice'), 'name'));
		self::assertStringContainsString('Collection-restricted', implode(' ', (new CalendarModule($this->createMock(ContainerInterface::class)))->guideNotes()));
	}
}
