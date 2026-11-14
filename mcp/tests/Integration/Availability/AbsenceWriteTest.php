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
 * Existing NC34/35 cells: A creates, then updates, its own absence over real HTTP with plan and confirm while B has a
 * future absence with start/end jobs. Each write touches only A's row and A's jobs; B's row and the exact rows of its
 * jobs stay intact, and those jobs still dispatch B's start and end events when run.
 */
final class AbsenceWriteTest extends IntegrationTestCase {
	/** @var list<IUser> owned disposable accounts */
	private array $actors = [];
	/** Previous MCP global switch, restored after the test. */
	private ?string $service = null;

	/** @return void remove only owned rows and jobs (exact arguments, never a class-wide remove) and accounts */
	protected function tearDown(): void {
		$jobs = Server::get(IJobList::class);
		$mapper = Server::get(AbsenceMapper::class);
		foreach ($this->actors as $actor) {
			$absence = Server::get(AbsenceService::class)->getAbsence($actor->getUID());
			if ($absence !== null) {
				foreach (['start', 'end'] as $event) $jobs->remove(OutOfOfficeEventDispatcherJob::class, ['id' => $absence->getId(), 'event' => $event]);
				$mapper->deleteByUserId($actor->getUID());
			}
			$actor->delete();
		}
		if ($this->service !== null) {
			$config = Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
		}
		parent::tearDown();
	}

	/** @return void create and update of A, each checked separately against B's row, jobs and firing events */
	public function testCreatingAndUpdatingOwnAbsencePreserveTheJobsOfAnotherUser(): void {
		$a = $this->actor(); $b = $this->actor(); $carol = $this->actor();
		$config = Server::get(IConfig::class); $this->service = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class); $policy->setGlobalEnabled(true); $policy->setEligible($a->getUID(), true); $policy->setConnected($a->getUID(), true);
		$client = new McpClient(LoginFactory::basic($a->getUID()));
		$year = (int)date('Y') + 1;
		$values = ['first_day' => "$year-02-01", 'last_day' => "$year-02-03", 'status' => 'Vacation', 'message' => 'Ask Carol', 'replacement_user_id' => $carol->getUID()];
		self::assertFalse($client->tool('availability_set_absence', $values)->ok(), 'the write starts denied');
		$policy->setGrant($a->getUID(), 'availability', 'absence', true);

		$bAbsence = Server::get(AbsenceService::class)->createOrUpdateAbsence($b, "$year-03-01", "$year-03-05", 'Away', 'B private message', null, null);
		$bId = (int)$bAbsence->getId();
		$before = $this->snapshotOf($bId);
		self::assertSame([['id' => $bId, 'event' => 'start'], ['id' => $bId, 'event' => 'end']], array_map(fn (array $row): array => $this->argument($row), $before['jobs']));

		// The update omits replacement_user_id: the stored replacement must be kept, never dropped silently.
		foreach (['create' => $values, 'update' => array_diff_key(['status' => 'Still away', 'last_day' => "$year-02-04"] + $values, ['replacement_user_id' => 0])] as $mode => $call) {
			$stored = Server::get(AbsenceService::class)->getAbsence($a->getUID())?->jsonSerialize();
			$plan = $client->tool('availability_set_absence', $call);
			self::assertTrue($plan->ok(), $plan->describe());
			self::assertSame($mode, $plan->structured()['mode']);
			self::assertSame($stored, Server::get(AbsenceService::class)->getAbsence($a->getUID())?->jsonSerialize(), "the $mode plan writes nothing");
			$saved = $client->tool('availability_set_absence', $call + ['confirm' => true, 'plan_state' => $plan->structured()['plan_state']]);
			self::assertTrue($saved->ok(), $saved->describe());
			self::assertTrue($saved->json()['saved']);
			self::assertSame($call['last_day'], $saved->json()['absence']['last_day']);
			self::assertSame($carol->getUID(), $saved->json()['absence']['replacement']['user_id'], 'the replacement is kept on update');
			self::assertStringNotContainsString('B private message', $saved->fingerprint());
			self::assertSame($before, $this->snapshotOf($bId), "$mode of A left B's absence and the exact rows of B's jobs unchanged");
			$aId = (int)Server::get(AbsenceService::class)->getAbsence($a->getUID())->getId();
			self::assertNotSame($bId, $aId);
			self::assertSame([['id' => $aId, 'event' => 'start'], ['id' => $aId, 'event' => 'end']], array_map(fn (array $row): array => $this->argument($row), $this->snapshotOf($aId)['jobs']), "$mode of A schedules only A's jobs");
		}

		$probe = new EventProbe(Server::get(IEventDispatcher::class), Server::get(IDBConnection::class));
		$probe->record(OutOfOfficeStartedEvent::class); $probe->record(OutOfOfficeEndedEvent::class);
		try {
			$jobs = Server::get(IJobList::class);
			foreach ($before['jobs'] as $row) {
				$job = $jobs->getById((string)$row['id']);
				self::assertInstanceOf(OutOfOfficeEventDispatcherJob::class, $job);
				$job->start($jobs);
			}
		} finally { $probe->detach(); }
		self::assertSame([OutOfOfficeStartedEvent::class, OutOfOfficeEndedEvent::class], $probe->classes());
		foreach ($probe->seen() as $seen) self::assertSame([$b->getUID(), (string)$bId], [$seen['event']->getData()->getUser()->getUID(), $seen['event']->getData()->getId()]);
	}

	/** @return IUser owned synthetic account */
	private function actor(): IUser {
		$uid = 'mcp-it-absence-' . bin2hex(random_bytes(4));
		$user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $user);
		return $this->actors[] = $user;
	}
	/**
	 * @param int $id absence ID
	 * @return array{absence:list<array<string,mixed>>,jobs:list<array<string,mixed>>} that absence's row and the exact rows of its jobs
	 */
	private function snapshotOf(int $id): array {
		$db = Server::get(IDBConnection::class);
		$absence = $db->getQueryBuilder();
		$absence->select('*')->from('dav_absence')->where($absence->expr()->eq('id', $absence->createNamedParameter($id)));
		$jobs = $db->getQueryBuilder();
		$jobs->select('*')->from('jobs')->where($jobs->expr()->eq('class', $jobs->createNamedParameter(OutOfOfficeEventDispatcherJob::class)))->orderBy('id');
		$own = array_values(array_filter($jobs->executeQuery()->fetchAllAssociative(), fn (array $row): bool => ($this->argument($row)['id'] ?? null) === $id));
		return ['absence' => $absence->executeQuery()->fetchAllAssociative(), 'jobs' => $own];
	}
	/**
	 * @param array<string,mixed> $row job row
	 * @return array<string,mixed> stored JSON argument
	 */
	private function argument(array $row): array { return json_decode((string)$row['argument'], true, 512, JSON_THROW_ON_ERROR); }
}
