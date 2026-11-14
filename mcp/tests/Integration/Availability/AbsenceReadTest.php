<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Availability;

use OCA\DAV\BackgroundJob\OutOfOfficeEventDispatcherJob;
use OCA\DAV\Db\AbsenceMapper;
use OCA\DAV\Service\AbsenceService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\EventProbe;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\OutOfOfficeEndedEvent;
use OCP\User\Events\OutOfOfficeStartedEvent;

/**
 * Existing NC34/35 cells: A reads its own absence over real HTTP while B has a future absence with start/end jobs.
 * B's absence row and the exact rows of its jobs stay intact, and those jobs still dispatch B's events when run.
 */
final class AbsenceReadTest extends IntegrationTestCase {
	/** @var list<IUser> owned disposable accounts */
	private array $actors = [];
	/** Previous MCP global switch, restored after the test. */
	private ?string $service = null;
	/** @var list<array{string,int}> owned absence rows to retire as (uid, id) */
	private array $absences = [];

	/** @return void remove only owned rows and jobs (exact arguments, never a class-wide remove) and accounts */
	protected function tearDown(): void {
		$jobs = Server::get(IJobList::class);
		foreach ($this->absences as [$uid, $id]) {
			foreach (['start', 'end'] as $event) $jobs->remove(OutOfOfficeEventDispatcherJob::class, ['id' => $id, 'event' => $event]);
			Server::get(AbsenceMapper::class)->deleteByUserId($uid);
		}
		foreach ($this->actors as $actor) $actor->delete();
		if ($this->service !== null) {
			$config = Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
		}
		parent::tearDown();
	}

	/** @return void the read of A writes nothing and leaves B's jobs runnable with their start and end events */
	public function testReadingOwnAbsencePreservesTheJobsOfAnotherUser(): void {
		$a = $this->actor(); $b = $this->actor();
		$config = Server::get(IConfig::class); $this->service = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class); $policy->setGlobalEnabled(true); $policy->setEligible($a->getUID(), true); $policy->setConnected($a->getUID(), true);
		$client = new McpClient(LoginFactory::basic($a->getUID()));
		self::assertFalse($client->tool('availability_read_absence')->ok(), 'new sensitive read remains opt-in');
		$policy->setGrant($a->getUID(), 'availability', 'read', true);

		$service = Server::get(AbsenceService::class);
		$year = (int)date('Y') + 1;
		$bAbsence = $service->createOrUpdateAbsence($b, "$year-03-01", "$year-03-05", 'Away', 'B private message', null, null);
		$bId = (int)$bAbsence->getId(); $this->absences[] = [$b->getUID(), $bId];
		$none = $client->tool('availability_read_absence');
		self::assertTrue($none->ok(), $none->describe());
		self::assertSame(['configured' => false, 'absence' => null], $none->json());
		self::assertStringNotContainsString('B private message', $none->fingerprint());

		$aAbsence = $service->createOrUpdateAbsence($a, "$year-02-01", "$year-02-02", 'Out', 'A message', null, null);
		$this->absences[] = [$a->getUID(), (int)$aAbsence->getId()];
		$before = $this->snapshot($b->getUID());
		self::assertSame([['id' => $bId, 'event' => 'start'], ['id' => $bId, 'event' => 'end']], $this->argumentsOf($before['jobs'], $bId));

		$own = $client->tool('availability_read_absence', ['user_id' => $b->getUID()]);
		self::assertFalse($own->ok(), 'arguments are refused; the account is never chosen by the caller');
		$own = $client->tool('availability_read_absence');
		self::assertTrue($own->ok(), $own->describe());
		self::assertSame('upcoming', $own->json()['absence']['state']);
		self::assertSame("$year-02-01", $own->json()['absence']['first_day']);
		self::assertSame('A message', $own->json()['absence']['message']);
		self::assertStringNotContainsString('B private message', $own->fingerprint());
		self::assertSame($before, $this->snapshot($b->getUID()), 'no absence row, background job or preference of B changed by the reads');

		$probe = new EventProbe(Server::get(IEventDispatcher::class), Server::get(IDBConnection::class));
		$probe->record(OutOfOfficeStartedEvent::class); $probe->record(OutOfOfficeEndedEvent::class);
		try {
			$jobs = Server::get(IJobList::class);
			foreach ($before['jobs'] as $row) {
				if ($this->argument($row)['id'] !== $bId) continue;
				$job = $jobs->getById((string)$row['id']);
				self::assertInstanceOf(OutOfOfficeEventDispatcherJob::class, $job);
				$job->start($jobs);
			}
		} finally { $probe->detach(); }
		self::assertSame([OutOfOfficeStartedEvent::class, OutOfOfficeEndedEvent::class], $probe->classes());
		foreach ($probe->seen() as $seen) {
			self::assertSame($b->getUID(), $seen['event']->getData()->getUser()->getUID());
			self::assertSame((string)$bId, $seen['event']->getData()->getId());
		}
	}

	/** @return IUser owned synthetic account */
	private function actor(): IUser {
		$uid = 'mcp-it-absence-' . bin2hex(random_bytes(4));
		$user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $user);
		return $this->actors[] = $user;
	}
	/**
	 * @param string $other the user whose absence must stay untouched; the actor's own login may update its own preferences
	 * @return array<string,list<array<string,mixed>>> every absence row, every out-of-office job row and the other user's preferences
	 */
	private function snapshot(string $other): array {
		$db = Server::get(IDBConnection::class);
		$absences = $db->getQueryBuilder(); $absences->select('*')->from('dav_absence')->orderBy('id');
		$jobs = $db->getQueryBuilder();
		$jobs->select('*')->from('jobs')->where($jobs->expr()->eq('class', $jobs->createNamedParameter(OutOfOfficeEventDispatcherJob::class)))->orderBy('id');
		$preferences = $db->getQueryBuilder();
		$preferences->select('*')->from('preferences')->where($preferences->expr()->eq('userid', $preferences->createNamedParameter($other)))
			->orderBy('appid')->addOrderBy('configkey');
		return ['absences' => $absences->executeQuery()->fetchAllAssociative(), 'jobs' => $jobs->executeQuery()->fetchAllAssociative(), 'preferences' => $preferences->executeQuery()->fetchAllAssociative()];
	}
	/**
	 * @param list<array<string,mixed>> $rows job rows
	 * @param int $id absence ID
	 * @return list<array<string,mixed>> decoded arguments of that absence's jobs
	 */
	private function argumentsOf(array $rows, int $id): array {
		return array_values(array_filter(array_map(fn (array $row): array => $this->argument($row), $rows), static fn (array $argument): bool => $argument['id'] === $id));
	}
	/**
	 * @param array<string,mixed> $row job row
	 * @return array<string,mixed> stored JSON argument
	 */
	private function argument(array $row): array { return json_decode((string)$row['argument'], true, 512, JSON_THROW_ON_ERROR); }
}
