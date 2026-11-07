<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Notification\NotificationHelper;
use OCA\Deck\Service\AssignmentService;
use OCA\Deck\Service\BoardService;
use OCA\Mcp\Modules\Deck\AssigneePasses;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Issue #26 with Nextcloud's own notification manager, NC 32.0.15 and NC 35.0.1, loaded from
 * tests/fixtures/nextcloud-core/notification (see sources.json), and the Notifications app's queue ({@see PushQueueApp}).
 *
 * Deck's `assignUser()` notifies before it inserts, as in Deck 1.16.8 and 1.19.0. Each case runs in its own process,
 * because each release declares its own `OC\Notification\Manager` and `OCP\Notification\IManager`.
 */
final class AssignmentPushDeferralTest extends TestCase {
	/** @var list<string> Recipients whose assignment Deck inserted, in order. */
	private array $inserted = [];
	/** @var list<string> Recipients whose assignment was saved before Deck failed (the handler's warning). */
	private array $warned = [];
	/** @var list<string> Recipients whose leftover notification the retry withdrew. */
	private array $withdrawn = [];
	/** Official manager of the release under test. */
	private object $manager;
	/** Notifications app registered in that manager. */
	private PushQueueApp $app;

	/** @return iterable<string, array{0: string}> Nextcloud majors whose official manager is loaded. */
	public static function releases(): iterable {
		yield 'NC32.0.15' => ['32'];
		yield 'NC35.0.1' => ['35'];
	}

	/**
	 * Loads the official manager of one release, registers the app double in it and builds the gateway.
	 *
	 * @param string $major Release folder under the fixture.
	 * @param list<string> $failing Recipients whose push fails while it is prepared.
	 * @param bool $foreignDeferral Whether someone else in the request already deferred delivery.
	 * @return DeckServiceGateway Gateway over a Deck that notifies before it inserts.
	 */
	private function gateway(string $major, array $failing, bool $foreignDeferral = false): DeckServiceGateway {
		require_once dirname(__DIR__, 4) . '/tests/Unit/Tools/Deck/Stubs/deck_stubs.php';
		$core = dirname(__DIR__, 3) . '/fixtures/nextcloud-core/notification/' . $major;
		require_once $core . '/IManager.php';
		require_once $core . '/Manager.php';
		$this->app = new PushQueueApp($failing);
		$reflection = new \ReflectionClass(\OC\Notification\Manager::class);
		$this->manager = $reflection->newInstanceWithoutConstructor();
		foreach (['apps' => [$this->app], 'appClasses' => [], 'deferPushing' => false] as $property => $value) {
			$reflection->getProperty($property)->setValue($this->manager, $value);
		}
		if ($foreignDeferral) {
			$this->manager->defer();
		}
		$deck = $this->createMock(AssignmentService::class);
		$deck->method('assignUser')->willReturnCallback(function (int $cardId, string $uid): Assignment {
			if ($uid === 'outsider') {
				throw new \OCA\Deck\BadRequestException('The user is not part of the board');
			}
			$notification = $this->createMock(INotification::class);
			$notification->method('isValid')->willReturn(true);
			$notification->method('getUser')->willReturn($uid);
			$this->manager->notify($notification);
			$this->inserted[] = $uid;
			return new Assignment(['cardId' => $cardId, 'participant' => $uid, 'type' => Acl::PERMISSION_TYPE_USER]);
		});
		$helper = $this->createMock(NotificationHelper::class);
		$helper->method('markCardAssignedAsRead')->willReturnCallback(function (Card $card, string $uid): void {
			$this->withdrawn[] = $uid;
		});
		$cards = $this->createMock(CardMapper::class);
		$cards->method('find')->willReturn(new Card(['id' => 9]));
		$services = [AssignmentService::class => $deck, BoardService::class => $this->createMock(BoardService::class),
			\OCP\Notification\IManager::class => $this->manager, NotificationHelper::class => $helper, CardMapper::class => $cards];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]
			?? throw new \LogicException('Unexpected container lookup: ' . $id));
		return new DeckServiceGateway($container, $this->createMock(ITimeFactory::class));
	}

	/**
	 * Assigns the recipients as `deck_edit_card` does: two passes, each write guarded by a read-back.
	 *
	 * @param DeckServiceGateway $gateway Gateway under test.
	 * @param list<string> $recipients Accounts to assign, in the caller's order.
	 * @return array<string, mixed> Outcome per recipient.
	 */
	private function assign(DeckServiceGateway $gateway, array $recipients): array {
		$writes = [];
		foreach ($recipients as $uid) {
			$writes[$uid] = function (bool $deferDelivery) use ($gateway, $uid): mixed {
				try {
					return $gateway->assignCardUser('alice', 9, $uid, $deferDelivery);
				} catch (\Throwable $e) {
					if (!in_array($uid, $this->inserted, true)) {
						throw $e;
					}
					$this->warned[] = $uid;
					return 'saved';
				}
			};
		}
		return AssigneePasses::run($writes);
	}

	/** @return bool Whether the official manager still defers delivery. */
	private function coreDeferring(): bool {
		return (new \ReflectionProperty(\OC\Notification\Manager::class, 'deferPushing'))->getValue($this->manager);
	}

	/** A recipient whose push fails no longer costs the assignment, and the healthy one after it still gets its push. */
	#[DataProvider('releases')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAFailingRecipientIsStoredAndTheNextOneStillGetsItsPush(string $major): void {
		$outcomes = $this->assign($this->gateway($major, ['bad']), ['bad', 'healthy']);
		self::assertSame(['healthy', 'bad'], $this->inserted);
		self::assertSame(['healthy'], $this->app->delivered);
		self::assertSame(['bad'], $this->warned);
		self::assertSame(['bad'], $this->withdrawn);
		self::assertSame(['healthy', 'bad'], array_keys($outcomes));
		self::assertNotInstanceOf(\Throwable::class, $outcomes['bad']);
	}

	/** Without the retry, the 1.2.5 path: the failing recipient is never assigned. */
	#[DataProvider('releases')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testTheDirectCallAloneLosesTheFailingRecipient(string $major): void {
		$gateway = $this->gateway($major, ['bad']);
		try {
			$gateway->assignCardUser('alice', 9, 'bad');
			self::fail('The direct call must fail before Deck stores the assignment.');
		} catch (\RuntimeException $e) {
			self::assertSame('push preparation failed', $e->getMessage());
		}
		self::assertSame([], $this->inserted);
	}

	/** After a failed flush the open deferral stays this request's: each later retry flushes and reports its own error. */
	#[DataProvider('releases')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testEveryRetryAfterAFailedFlushStillFlushes(string $major): void {
		$this->assign($this->gateway($major, ['bad1', 'bad2']), ['bad1', 'bad2']);
		self::assertSame(['bad1', 'bad2'], $this->inserted);
		self::assertSame(['bad1', 'bad2'], $this->warned);
		self::assertSame(2, $this->app->flushes);
		self::assertSame([], $this->app->delivered);
	}

	/** Recipients whose push works take the 1.2.5 path only: inline delivery, no deferral, no flush. */
	#[DataProvider('releases')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testHealthyRecipientsKeepTheDirectPath(string $major): void {
		$this->assign($this->gateway($major, []), ['one', 'two']);
		self::assertSame(['one', 'two'], $this->app->delivered);
		self::assertSame(0, $this->app->flushes);
		self::assertSame([], $this->withdrawn);
		self::assertFalse($this->coreDeferring());
	}

	/** A deferral someone else opened stays theirs: the retry stores the assignment and leaves the flush to them. */
	#[DataProvider('releases')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAForeignDeferralIsLeftToItsOwner(string $major): void {
		$this->assign($this->gateway($major, ['bad'], true), ['bad', 'healthy']);
		self::assertSame(['bad', 'healthy'], $this->inserted);
		self::assertSame(0, $this->app->flushes);
		self::assertSame(['bad', 'healthy'], $this->app->queue);
		self::assertTrue($this->coreDeferring());
	}

	/** A Deck refusal is final: it is never retried and nothing is deferred for it. */
	#[DataProvider('releases')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testADeckRefusalIsNotRetried(string $major): void {
		$outcomes = $this->assign($this->gateway($major, []), ['outsider', 'healthy']);
		self::assertInstanceOf(\OCA\Deck\BadRequestException::class, $outcomes['outsider']);
		self::assertSame(['healthy'], $this->inserted);
		self::assertSame([], $this->withdrawn);
		self::assertSame(0, $this->app->flushes);
	}
}
