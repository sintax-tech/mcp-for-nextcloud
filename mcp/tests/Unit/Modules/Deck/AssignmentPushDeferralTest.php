<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Service\AssignmentService;
use OCA\Deck\Service\BoardService;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/Tools/Deck/Stubs/deck_stubs.php';

/**
 * Issue #26: the assignee's push delivery cannot abort a Deck assignment.
 *
 * The notification manager double behaves as the Notifications app does with a push device: outside a deferral
 * `notify()` delivers inline and the delivery error escapes; inside it the push is queued and `flush()` delivers it.
 * Deck's `assignUser()` notifies before it inserts, as in Deck 1.16.8 and 1.19.0.
 */
final class AssignmentPushDeferralTest extends TestCase {
	/** @var list<string> Ordered events of the manager and of the Deck insert. */
	private array $events = [];
	/** @var list<string> Accounts whose assignment Deck stored. */
	private array $stored = [];
	/** Whether a deferral is open in the request. */
	private bool $deferred = false;
	/** Pushes queued inside the open deferral. */
	private int $queued = 0;
	/** Whether delivering a push fails, as with an unreachable push service. */
	private bool $deliveryFails = true;

	/**
	 * Builds the gateway over a Deck assignment service that notifies before inserting.
	 *
	 * @param \Throwable|null $deckError Error Deck raises after notifying, instead of inserting.
	 * @return DeckServiceGateway Gateway whose container hands out the doubles.
	 */
	private function gateway(?\Throwable $deckError = null): DeckServiceGateway {
		$manager = $this->createMock(IManager::class);
		$manager->method('defer')->willReturnCallback(function (): bool {
			$this->events[] = 'defer';
			$owner = !$this->deferred;
			$this->deferred = true;
			return $owner;
		});
		$manager->method('flush')->willReturnCallback(function (): void {
			$this->events[] = 'flush';
			$this->deferred = false;
			$pending = $this->queued;
			$this->queued = 0;
			if ($pending > 0) {
				$this->deliver();
			}
		});
		$manager->method('notify')->willReturnCallback(function (): void {
			if ($this->deferred) {
				$this->events[] = 'notify:queued';
				$this->queued++;
				return;
			}
			$this->events[] = 'notify:inline';
			$this->deliver();
		});
		$notification = $this->createMock(INotification::class);
		$deck = $this->createMock(AssignmentService::class);
		$deck->method('assignUser')->willReturnCallback(
			function (int $cardId, string $uid) use ($manager, $notification, $deckError): Assignment {
				$manager->notify($notification);
				if ($deckError !== null) {
					throw $deckError;
				}
				$this->events[] = 'insert';
				$this->stored[] = $uid;
				return new Assignment(['cardId' => $cardId, 'participant' => $uid, 'type' => Acl::PERMISSION_TYPE_USER]);
			}
		);
		$services = [
			AssignmentService::class => $deck,
			BoardService::class => $this->createMock(BoardService::class),
			IManager::class => $manager,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]
			?? throw new \LogicException('Unexpected container lookup: ' . $id));

		return new DeckServiceGateway($container, $this->createMock(ITimeFactory::class));
	}

	/**
	 * Delivers one push the way the double is configured.
	 *
	 * @return void
	 * @throws RuntimeException When delivery fails.
	 */
	private function deliver(): void {
		$this->events[] = 'deliver';
		if ($this->deliveryFails) {
			throw new RuntimeException('push service unreachable');
		}
	}

	/** A failing delivery no longer aborts the assignment: it is stored first and the error comes after the write. */
	public function testFailingDeliveryComesAfterTheAssignmentIsStored(): void {
		$gateway = $this->gateway();
		try {
			$gateway->assignCardUser('alice', 9, 'pedro');
			self::fail('The delivery error must reach the caller after the write.');
		} catch (RuntimeException $e) {
			self::assertSame('push service unreachable', $e->getMessage());
		}
		self::assertSame(['pedro'], $this->stored);
		self::assertSame(['defer', 'notify:queued', 'insert', 'flush', 'deliver'], $this->events);
		self::assertFalse($this->deferred);
	}

	/** With a working push service the assignment returns normally and the push is delivered once, after the insert. */
	public function testWorkingDeliveryIsSentOnceAfterTheInsert(): void {
		$this->deliveryFails = false;
		$assignment = $this->gateway()->assignCardUser('alice', 9, 'pedro');
		self::assertSame('pedro', $assignment->getParticipant());
		self::assertSame(['defer', 'notify:queued', 'insert', 'flush', 'deliver'], $this->events);
		self::assertFalse($this->deferred);
	}

	/** A deferral opened by someone else in the request stays theirs to flush. */
	public function testForeignDeferralIsNotFlushed(): void {
		$this->deferred = true;
		$this->gateway()->assignCardUser('alice', 9, 'pedro');
		self::assertSame(['defer', 'notify:queued', 'insert'], $this->events);
		self::assertTrue($this->deferred);
		self::assertSame(1, $this->queued);
	}

	/** A Deck refusal still closes the deferral it opened, and the refusal, not the delivery error, reaches the caller. */
	public function testDeckErrorWinsAndTheDeferralIsClosed(): void {
		$refusal = new \OCA\Deck\BadRequestException('The user is not part of the board');
		try {
			$this->gateway($refusal)->assignCardUser('alice', 9, 'pedro');
			self::fail('The Deck refusal must reach the caller.');
		} catch (\Throwable $e) {
			self::assertSame($refusal, $e);
		}
		self::assertSame([], $this->stored);
		self::assertSame(['defer', 'notify:queued', 'flush', 'deliver'], $this->events);
		self::assertFalse($this->deferred);
	}
}
