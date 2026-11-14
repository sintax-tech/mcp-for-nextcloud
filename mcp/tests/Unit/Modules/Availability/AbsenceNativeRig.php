<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Availability;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Availability\AvailabilityModule;
use OCA\Mcp\Modules\Availability\Editing\AbsenceInput;
use OCA\Mcp\Modules\Availability\Editing\SetAbsenceHandler;
use OCA\Mcp\Modules\Availability\Editing\SetAbsencePlan;
use OCA\Mcp\Modules\Availability\Editing\SetAbsencePreview;
use OCA\Mcp\Modules\Availability\Integration\AbsenceGateway;
use OCA\Mcp\Modules\Availability\Integration\AbsenceSurface;
use OCA\Mcp\Modules\Availability\Integration\AbsenceWriter;
use OCA\Mcp\Modules\Availability\Reading\AbsenceView;
use OCA\Mcp\Modules\Availability\Reading\ReadAbsenceHandler;
use OCA\Mcp\Modules\Availability\Tools\ReadAbsence;
use OCA\Mcp\Modules\Availability\Tools\SetAbsence;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\IAvailabilityCoordinator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * The real DAV AbsenceService, Absence entity and OutOfOfficeEventDispatcherJob, verbatim from server v34.0.4/v35.0.1,
 * over an in-memory absence table, job list and event dispatcher that record every call. Only the mapper's SELECTs
 * answer from the table; every other mapper, job list, dispatcher or coordinator call is logged as an effect. The job
 * list keeps one job per class and argument, as JobList::add does (34.0.4:53-75, 35.0.1:54-76).
 */
final class AbsenceNativeRig {
	/** @var array<int,object> stored absences by native ID */
	public array $rows = [];
	/** @var list<array{class:string,time:int,argument:mixed}> scheduled background jobs */
	public array $jobs = [];
	/** @var list<object> dispatched typed events */
	public array $events = [];
	/** @var list<string> every mapper/job list/dispatcher/coordinator call that is not a plain read */
	public array $effects = [];
	/** @var list<string> SELECTs answered by the mapper, as "method:argument" */
	public array $selects = [];
	/** Controlled server clock. */
	public int $now = 1800000000;
	/** Instance-wide absence feature switch. */
	public bool $enabled = true;
	/** @var array<string,string> user time zones; others fall back to the server default */
	public array $zones = ['alice' => 'America/Sao_Paulo', 'bob' => 'Europe/Berlin'];
	/** @var array<string,string> existing accounts and their display names */
	public array $names = ['alice' => 'Alice', 'bob' => 'Bob', 'carol' => 'Carol'];
	/** Grants of the tools; every grant of alice is on unless a test turns one off. */
	public GrantPolicy $policy;
	/** Native service under test. */
	public object $service;
	/** Verbatim job with the same doubles. */
	public object $job;
	/** Read work class over the real adapter. */
	public ReadAbsenceHandler $handler;
	/** Write tool over the real adapter. */
	public SetAbsence $set;
	/** @var array<class-string,object> what the module's container serves */
	private array $services = [];

	/** @return void load the pinned server sources once, after checking their hashes */
	public static function load(): void {
		$pins = json_decode((string)file_get_contents(self::dir() . 'sources.json'), true, 512, JSON_THROW_ON_ERROR)['files'];
		foreach ($pins as $file => $pin) {
			if (hash_file('sha256', self::dir() . $file) !== $pin['sha256']) throw new \RuntimeException('Absence source SHA256 mismatch: ' . $file);
		}
		foreach (['OutOfOfficeData', 'Absence', 'AbsenceMapper', 'TimezoneService', 'AbsenceService', 'OutOfOfficeEventDispatcherJob'] as $file) require_once self::dir() . $file . '.php';
	}
	/** @return string directory of the verbatim sources */
	public static function dir(): string { return __DIR__ . '/../../../fixtures/absence-native/'; }

	/**
	 * @param TestCase $test owner of the doubles
	 * @param GrantPolicy|null $policy grants to use; null builds one with every grant of alice on
	 */
	public function __construct(private TestCase $test, ?GrantPolicy $policy = null) {
		self::load();
		$mapper = $this->mapper();
		$jobs = $this->jobList();
		$events = $this->mock(IEventDispatcher::class);
		foreach ($this->publicMethods(IEventDispatcher::class) as $name) {
			$events->method($name)->willReturnCallback(function (...$args) use ($name) {
				$this->effects[] = 'events.' . $name;
				if ($name === 'dispatchTyped') $this->events[] = $args[0];
			});
		}
		$zones = $this->test->getMockBuilder(AbsenceSurface::TIMEZONE)->disableOriginalConstructor()->getMock();
		$zones->method('getUserTimezone')->willReturnCallback(fn (string $uid): ?string => $this->zones[$uid] ?? null);
		$zones->method('getDefaultTimezone')->willReturn('UTC');
		$time = $this->mock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->service = new (AbsenceSurface::SERVICE)($mapper, $events, $jobs, $zones, $time);
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => isset($this->names[$uid]) ? $this->user($uid) : null);
		$this->job = new ('OCA\\DAV\\BackgroundJob\\OutOfOfficeEventDispatcherJob')($time, $mapper, $this->mock(LoggerInterface::class), $events, $users, $zones);
		$coordinator = $this->mock(IAvailabilityCoordinator::class);
		foreach ($this->publicMethods(IAvailabilityCoordinator::class) as $name) {
			$coordinator->method($name)->willReturnCallback(function (...$args) use ($name) {
				if ($name === 'isEnabled') return $this->enabled;
				$this->effects[] = 'coordinator.' . $name . ($name === 'clearCache' ? ':' . $args[0] : '');
				return null;
			});
		}
		$native = $this->mock(ContainerInterface::class);
		$native->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
			AbsenceSurface::SERVICE => $this->service, AbsenceSurface::TIMEZONE => $zones, AbsenceSurface::COORDINATOR => $coordinator,
			default => throw new \LogicException('Unexpected service: ' . $id),
		});
		if ($policy === null) {
			$config = (new InMemoryConfig())->mock($test);
			$policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
			foreach (['read', 'absence'] as $operation) $policy->setGrant('alice', 'availability', $operation, true);
		}
		$this->policy = $policy;
		$secret = $this->mock(IConfig::class);
		$secret->method('getSystemValueString')->willReturn('rig-secret');
		$gateway = new AbsenceGateway($native, new AbsenceSurface(), $users, $time);
		$view = new AbsenceView();
		$this->handler = new ReadAbsenceHandler($gateway, $view);
		$plans = new SetAbsencePlan($gateway, new AbsenceInput(), $view, $users, new PlanState($secret));
		$this->set = new SetAbsence(new SetAbsenceHandler($plans, $policy, $gateway, new AbsenceWriter($native, new AbsenceSurface(), $users), $view), new SetAbsencePreview($plans));
		$this->services = [ReadAbsence::class => new ReadAbsence($this->handler), SetAbsence::class => $this->set];
	}

	/** @return AvailabilityModule the module over this rig, as the container would build it */
	public function module(): AvailabilityModule {
		$container = $this->mock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $this->services[$id] ?? throw new \LogicException('Unexpected tool: ' . $id));
		return new AvailabilityModule($container);
	}
	/**
	 * Stores an absence the way the native POST does, through the real createOrUpdateAbsence; seeding only.
	 *
	 * @param string $uid owner
	 * @param string $first first day
	 * @param string $last last day
	 * @param string|null $replacement replacement user ID
	 * @return int native absence ID
	 */
	public function seed(string $uid, string $first, string $last, ?string $replacement = null): int {
		$absence = $this->service->createOrUpdateAbsence($this->user($uid), $first, $last, 'Away', 'Back soon', $replacement, $replacement === null ? null : $this->names[$replacement]);
		return (int)$absence->getId();
	}
	/** @return void forget the effects of seeding, keeping the stored state */
	public function settle(): void { $this->effects = []; $this->events = []; $this->selects = []; }
	/**
	 * @param int $id native absence ID
	 * @return list<array{class:string,time:int,argument:mixed}> that absence's jobs
	 */
	public function jobsOf(int $id): array { return array_values(array_filter($this->jobs, static fn (array $job): bool => $job['argument']['id'] === $id)); }
	/** @return array<int,array<string,mixed>> every stored row with its ID */
	public function table(): array { return array_map(static fn (object $row): array => $row->jsonSerialize() + ['id' => $row->getId()], $this->rows); }
	/**
	 * @param string $uid account
	 * @return IUser native user double
	 */
	public function user(string $uid): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturnCallback(fn (): string => $this->names[$uid] ?? $uid);
		return $user;
	}

	/** @return object AbsenceMapper double answering SELECTs from rows and logging everything else */
	private function mapper(): object {
		$class = 'OCA\\DAV\\Db\\AbsenceMapper';
		$mapper = $this->test->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods($this->publicMethods($class))->getMock();
		foreach ($this->publicMethods($class) as $name) {
			$mapper->method($name)->willReturnCallback(function (...$args) use ($name) {
				if ($name === 'findByUserId' || $name === 'findById') {
					$this->selects[] = $name . ':' . $args[0];
					foreach ($this->rows as $id => $row) if (($name === 'findById' ? $id : $row->getUserId()) === $args[0]) return clone $row;
					throw new DoesNotExistException('none');
				}
				$this->effects[] = 'mapper.' . $name;
				if ($name === 'insert') { $args[0]->setId(count($this->rows) + 101); }
				if ($name === 'insert' || $name === 'update') { $this->rows[$args[0]->getId()] = clone $args[0]; return $args[0]; }
				return null;
			});
		}
		return $mapper;
	}
	/** @return IJobList recording job list; scheduleAfter inserts or updates by class and argument, as JobList::add */
	private function jobList(): IJobList {
		$jobs = $this->mock(IJobList::class);
		foreach ($this->publicMethods(IJobList::class) as $name) {
			$jobs->method($name)->willReturnCallback(function (...$args) use ($name) {
				$this->effects[] = 'jobs.' . $name;
				if ($name === 'scheduleAfter') {
					foreach ($this->jobs as $i => $job) {
						if ($job['class'] === $args[0] && $job['argument'] === $args[2]) { $this->jobs[$i]['time'] = $args[1]; return null; }
					}
					$this->jobs[] = ['class' => $args[0], 'time' => $args[1], 'argument' => $args[2]];
				}
				return match ($name) { 'has', 'hasReservedJob' => false, 'getJobs', 'countByClass' => [], 'getJobsIterator' => [], default => null };
			});
		}
		return $jobs;
	}
	/**
	 * @param class-string $class owner
	 * @return list<string> public instance methods, constructor excluded
	 */
	private function publicMethods(string $class): array {
		$names = [];
		foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if (!$method->isStatic() && !$method->isConstructor() && !str_starts_with($method->getName(), '__')) $names[] = $method->getName();
		}
		return $names;
	}
	/**
	 * @template T of object
	 * @param class-string<T> $class interface
	 * @return T&\PHPUnit\Framework\MockObject\MockObject double
	 */
	private function mock(string $class): object { return $this->test->getMockBuilder($class)->getMock(); }
}
