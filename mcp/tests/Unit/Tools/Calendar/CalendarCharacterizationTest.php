<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use DateTimeImmutable;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Calendar\Composing\AttendeeResolver;
use OCA\Mcp\Modules\Calendar\Composing\DateInput;
use OCA\Mcp\Modules\Calendar\Composing\EventBuilder;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventDraft;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventDraft;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Moving\EventRelocator;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventDraft;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Modules\Calendar\Writing\TrashPolicy;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Tests\Unit\Contract\EffectContracts\CalendarEffectsWiring;
use OCA\Mcp\Tests\Unit\Contract\EffectContracts\CalendarWritesScene;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of the Calendar call paths, recorded before the module was split into catalog tools (RE-16 b0) and
 * compared byte for byte on every later commit, each run in a process of its own.
 *
 * Every scenario starts from a fresh scene and records, step by step, what the module answers to a plan (preview), to a
 * confirmed call and to the direct write the selftest uses: the result, or the class, message, code and previous class
 * of the exception that leaves the module, and then the requests the CalDAV double received, what the scheduler was
 * handed and the stored objects. Random UIDs and the ETags derived from them are numbered by first appearance.
 *
 * No step plans an overlap with another event: an overlap warning formats its times with ext-intl, which php:8.3-cli
 * does not have, so the recording would depend on the image.
 *
 * Set MCP_CHARACTERIZE_WRITE=1 to record the fixture again; it must only ever be recorded on the commit before the split.
 */
final class CalendarCharacterizationTest extends TestCase {
	/** Fixture the recorded run is compared with. */
	private const FIXTURE = __DIR__ . '/../../../fixtures/calendar-characterization.json';
	/** The second calendar bob shares with alice read-write (id 4). */
	private const OTHER = '/remote.php/dav/calendars/alice/other_shared_by_bob/';
	/** A calendar alice cannot see. */
	private const UNKNOWN = '/remote.php/dav/calendars/alice/nope/';

	private CalendarWritesScene $scene;
	private bool $trashOn = true;
	private bool $usersFail = false;

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testTheCallPathsAnswerAsBeforeTheSplit(): void {
		$recorded = [];
		foreach ($this->scenarios() as $name => $steps) {
			$recorded[$name] = $this->scenario($steps);
		}
		$json = self::normalized(json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . "\n";
		if (getenv('MCP_CHARACTERIZE_WRITE') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}
		self::assertSame((string)file_get_contents(self::FIXTURE), $json);
	}

	/**
	 * @return array<string, list<array{0:string, 1:string, 2:array<string, mixed>}|callable>> steps per scenario: [kind, tool, arguments], kind being
	 *     preview, call or direct; a callable prepares the scene before the next step
	 */
	private function scenarios(): array {
		$p = CalendarWritesScene::PERSONAL; $w = CalendarWritesScene::WORK; $t = CalendarWritesScene::TEAM;
		$create = ['summary' => 'Reunião', 'start' => '2026-10-02T09:00:00Z', 'end' => '2026-10-02T10:00:00Z'];
		$stale = '"stale"';
		return [
			'list calendars' => [['call', 'calendar_list_calendars', []]],
			'list events' => [['call', 'calendar_list_events', ['from' => '2026-09-01', 'to' => '2026-12-31']],
				['call', 'calendar_list_events', ['calendar' => $p, 'from' => '2026-09-01', 'to' => '2026-12-31']],
				['call', 'calendar_list_events', ['calendar' => self::UNKNOWN]],
				['call', 'calendar_list_events', ['from' => 'not a date']]],
			'create' => [['preview', 'calendar_create_event', ['calendar' => $p] + $create],
				['call', 'calendar_create_event', ['calendar' => $p, 'confirm' => true] + $create],
				['call', 'calendar_create_event', ['calendar' => $p, 'confirm' => true, 'attendees' => ['bob'], 'send_invitations' => true] + $create],
				['preview', 'calendar_create_event', ['calendar' => $p, 'start' => 'x'] + $create],
				['call', 'calendar_create_event', ['calendar' => $p, 'confirm' => true, 'start' => 'x'] + $create],
				['call', 'calendar_create_event', ['calendar' => self::UNKNOWN, 'confirm' => true] + $create]],
			'create shared' => [['preview', 'calendar_create_event', ['calendar' => $t] + $create],
				['call', 'calendar_create_event', ['calendar' => $t, 'confirm' => true] + $create],
				['call', 'calendar_create_event', ['calendar' => $t, 'confirm' => true, 'confirm_shared' => true] + $create]],
			'update' => [['preview', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-a', 'summary' => 'A2']],
				['call', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-a', 'summary' => 'A2', 'confirm' => true, 'etag' => $stale]],
				['call', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-a', 'summary' => 'A2', 'location' => 'Sala', 'confirm' => true]],
				['call', 'calendar_update_event', ['calendar' => self::UNKNOWN, 'uid' => 'evt-a', 'confirm' => true]],
				['call', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-r', 'start' => '2026-10-05T09:00:00Z', 'confirm' => true]],
				['call', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-r', 'attendees' => ['bob'], 'confirm' => true]],
				['call', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-a', 'allDay' => true, 'confirm' => true]],
				['call', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-g', 'attendees' => [], 'send_invitations' => true, 'confirm' => true]]],
			'update shared' => [['preview', 'calendar_update_event', ['calendar' => $t, 'uid' => 'evt-t', 'summary' => 'T2']],
				['call', 'calendar_update_event', ['calendar' => $t, 'uid' => 'evt-t', 'summary' => 'T2', 'confirm' => true]],
				['call', 'calendar_update_event', ['calendar' => $t, 'uid' => 'evt-t', 'summary' => 'T2', 'confirm' => true, 'confirm_shared' => true]]],
			'move' => [['preview', 'calendar_move_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $w]],
				['call', 'calendar_move_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $p, 'confirm' => true]],
				['call', 'calendar_move_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $t, 'confirm' => true]],
				['call', 'calendar_move_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $w, 'etag' => $stale, 'confirm' => true]],
				['call', 'calendar_move_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $w, 'confirm' => true]],
				['call', 'calendar_move_event', ['calendar' => $w, 'uid' => 'evt-a', 'targetCalendar' => self::UNKNOWN, 'confirm' => true]]],
			'move between shared' => [['preview', 'calendar_move_event', ['calendar' => $t, 'uid' => 'evt-t', 'targetCalendar' => self::OTHER]],
				['call', 'calendar_move_event', ['calendar' => $t, 'uid' => 'evt-t', 'targetCalendar' => self::OTHER, 'confirm' => true]],
				['call', 'calendar_move_event', ['calendar' => $t, 'uid' => 'evt-t', 'targetCalendar' => self::OTHER, 'confirm' => true, 'confirm_shared' => true]]],
			'transfer' => [['preview', 'calendar_transfer_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => self::OTHER]],
				['call', 'calendar_transfer_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $w, 'confirm' => true]],
				['call', 'calendar_transfer_event', ['calendar' => $p, 'uid' => 'evt-g', 'targetCalendar' => $t, 'confirm' => true, 'confirm_shared' => true]],
				['call', 'calendar_transfer_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $t, 'confirm' => true]],
				['call', 'calendar_transfer_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $t, 'confirm' => true, 'confirm_shared' => true]],
				['preview', 'calendar_transfer_event', ['calendar' => $t, 'uid' => 'evt-t', 'targetCalendar' => $w]],
				['call', 'calendar_transfer_event', ['calendar' => $t, 'uid' => 'evt-t', 'targetCalendar' => $p, 'confirm' => true, 'confirm_shared' => true]]],
			'delete' => [['preview', 'calendar_delete_event', ['calendar' => $p, 'uid' => 'evt-a']],
				['call', 'calendar_delete_event', ['calendar' => $p, 'uid' => 'evt-a', 'etag' => $stale, 'confirm' => true]],
				['call', 'calendar_delete_event', ['calendar' => $p, 'uid' => 'evt-g', 'send_invitations' => true, 'confirm' => true]],
				['call', 'calendar_delete_event', ['calendar' => $p, 'uid' => 'evt-a', 'confirm' => true]],
				['call', 'calendar_delete_event', ['calendar' => $t, 'uid' => 'evt-t', 'confirm' => true]],
				['call', 'calendar_delete_event', ['calendar' => $t, 'uid' => 'evt-t', 'confirm' => true, 'confirm_shared' => true]]],
			'delete without trash' => [fn () => $this->trashOn = false,
				['preview', 'calendar_delete_event', ['calendar' => $p, 'uid' => 'missing']],
				['call', 'calendar_delete_event', ['calendar' => $p, 'uid' => 'missing', 'confirm' => true]]],
			'library failure' => [fn () => $this->usersFail = true,
				['preview', 'calendar_update_event', ['calendar' => $t, 'uid' => 'evt-t', 'summary' => 'T2']],
				['call', 'calendar_update_event', ['calendar' => $t, 'uid' => 'evt-t', 'summary' => 'T2', 'confirm' => true, 'confirm_shared' => true]],
				['call', 'calendar_list_calendars', []]],
			'dav failure' => [fn () => $this->scene->dav->failureFor['update'] = new \InvalidArgumentException('library detail'),
				['preview', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-a', 'summary' => 'A2']],
				['call', 'calendar_update_event', ['calendar' => $p, 'uid' => 'evt-a', 'summary' => 'A2', 'confirm' => true]],
				fn () => $this->scene->dav->failureFor['delete'] = new \RuntimeException('server detail'),
				['call', 'calendar_delete_event', ['calendar' => $p, 'uid' => 'evt-a', 'confirm' => true]]],
			'direct writes' => [['direct', 'calendar_create_event', ['calendar' => $t] + $create],
				['direct', 'calendar_create_event', ['calendar' => $t, 'confirm_shared' => true] + $create],
				['direct', 'calendar_move_event', ['calendar' => $t, 'uid' => 'evt-t', 'targetCalendar' => self::OTHER]],
				['direct', 'calendar_move_event', ['calendar' => $p, 'uid' => 'evt-a', 'targetCalendar' => $w]],
				['direct', 'calendar_transfer_event', ['calendar' => $w, 'uid' => 'evt-a', 'targetCalendar' => $t, 'confirm' => true]],
				['direct', 'calendar_delete_event', ['calendar' => $t, 'uid' => 'evt-t']],
				['direct', 'calendar_delete_event', ['calendar' => $t, 'uid' => 'evt-t', 'confirm' => true, 'confirm_shared' => true]],
				['direct', 'calendar_delete_event', ['calendar' => $p, 'uid' => 'missing']],
				['direct', 'calendar_create_event', ['calendar' => $p, 'start' => 'x'] + $create],
				['direct', 'calendar_move_event', ['calendar' => $t, 'uid' => 'evt-a', 'targetCalendar' => self::OTHER, 'etag' => $stale]]],
		];
	}

	/**
	 * @param list<array{0:string, 1:string, 2:array<string, mixed>}|callable> $steps one scenario
	 * @return list<array<string, mixed>> what each step answered and left behind
	 */
	private function scenario(array $steps): array {
		$this->scene = new CalendarWritesScene();
		$this->trashOn = true;
		$this->usersFail = false;
		$this->scene->store->addCalendar(CalendarWritesScene::ALICE, 4, 'other_shared_by_bob', CalendarWritesScene::BOB, ['name' => 'Outra (bob)']);
		$this->scene->event(1, 'evt-a', 'A');
		$this->scene->event(1, 'evt-g', 'G', ['bob']);
		$this->scene->event(3, 'evt-t', 'T');
		$this->scene->store->addObject(1, 'evt-r.ics', str_replace("\n", "\r\n", "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//test//EN\nBEGIN:VEVENT\n"
			. "UID:evt-r\nDTSTAMP:20260301T100000Z\nSUMMARY:R\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z\nRRULE:FREQ=WEEKLY;COUNT=3\n"
			. "END:VEVENT\nEND:VCALENDAR\n"));
		$out = [];
		foreach ($steps as $step) {
			if ($step instanceof \Closure) {
				$step();
				continue;
			}
			[$kind, $tool, $arguments] = $step;
			$before = count($this->scene->dav->calls);
			$out[] = ['step' => $kind . ' ' . $tool, 'arguments' => $arguments, 'outcome' => $this->outcome(fn (): array => match ($kind) {
				'preview' => $this->module()->preview($tool, $arguments, 'alice'),
				'call' => $this->module()->call($tool, $arguments, 'alice'),
				'direct' => $this->draft($tool)->execute($arguments, 'alice'),
			}), 'dav' => array_slice($this->scene->state()['dav'], $before)];
		}
		$state = $this->scene->state();
		unset($state['dav']);
		$out[] = ['state' => $state];
		return $out;
	}

	/**
	 * @param callable(): array<string, mixed> $call one step
	 * @return array<string, mixed> the answer, or the exception chain that left the module
	 */
	private function outcome(callable $call): array {
		try {
			return ['result' => $call()];
		} catch (\Throwable $e) {
			$chain = [];
			for ($x = $e; $x !== null; $x = $x->getPrevious()) {
				$chain[] = ['class' => $x::class, 'message' => $x->getMessage(), 'code' => $x->getCode()];
			}
			return ['exception' => $chain];
		}
	}

	/** @return ToolModule the module of one request, wired as the container wires it */
	private function module(): ToolModule {
		return CalendarEffectsWiring::module($this, $this->scene->store, $this->scene->dav, $this->config(), $this->users());
	}

	/**
	 * @param string $tool write tool name
	 * @return CreateEventDraft|MoveEventDraft|TransferEventDraft|DeleteEventDraft the draft the selftest calls directly
	 */
	private function draft(string $tool): CreateEventDraft|MoveEventDraft|TransferEventDraft|DeleteEventDraft {
		$store = $this->scene->store;
		$dav = $this->scene->dav;
		$users = $this->users();
		$access = ScopeStub::access($store);
		$repository = new EventRepository($store, new Classification());
		$guard = new SharedGuard($users);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(CalendarEffectsWiring::NOW));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$scheduling = new Scheduling($appConfig);
		$relocator = new EventRelocator($access, $store, $dav, $repository);
		return match ($tool) {
			'calendar_create_event' => new CreateEventDraft($access, $guard, $dav, $store, $repository, new EventBuilder(), new EventMapper(), new DateInput(), new AttendeeResolver($users), $scheduling, $time),
			'calendar_move_event' => new MoveEventDraft($access, $relocator, $guard),
			'calendar_transfer_event' => new TransferEventDraft($relocator),
			'calendar_delete_event' => new DeleteEventDraft($access, $guard, $dav, $store, $repository, new TrashPolicy($this->config()), $scheduling),
		};
	}

	/** @return IConfig the configuration: no account timezone, and the calendar trash on unless the scenario turned it off */
	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $key === 'calendarRetentionObligation' && !$this->trashOn ? '0' : $default);
		$config->method('getUserValue')->willReturnCallback(static fn (string $user, string $app, string $key, mixed $default = ''): mixed => $default);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $default);
		return $config;
	}

	/** @return IUserManager the accounts of the scene, or one whose lookups throw a library InvalidArgumentException */
	private function users(): IUserManager {
		if (!$this->usersFail) {
			return CalendarWritesScene::users($this);
		}
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => throw new \InvalidArgumentException('library detail'));
		return $users;
	}

	/**
	 * @param string $json the recorded run
	 * @return string the same run with every random UUID and every MD5 ETag numbered by first appearance
	 */
	private static function normalized(string $json): string {
		foreach (['/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/' => 'UUID', '/(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])/' => 'MD5'] as $pattern => $label) {
			$seen = [];
			$json = (string)preg_replace_callback($pattern, static function (array $m) use (&$seen, $label): string {
				return $label . '-' . ($seen[$m[0]] ??= count($seen) + 1);
			}, $json);
		}
		return $json;
	}
}
