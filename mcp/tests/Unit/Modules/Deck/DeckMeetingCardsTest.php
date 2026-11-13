<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Deck\DeckMeetingCards;
use OCA\Mcp\Modules\Deck\DeckReadDispatch;
use OCA\Mcp\Modules\Deck\Integration\AssignedCardLookup;
use OCA\Mcp\Modules\Deck\Reading\ListCardFilesHandler;
use OCA\Mcp\Modules\Deck\Reading\ReadCardHandler;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** deck_read_card and deck_list_card_files are the only Deck names a meeting dossier dispatches. */
final class DeckMeetingCardsTest extends TestCase {
	private bool $granted = true;
	private bool $appEnabled = true;
	private bool $guestAllowed = true;
	private bool $unavailable = false;
	/** @var list<array{string, string, list<string>}> */
	private array $asked = [];
	/** @var list<array{string, string}> */
	private array $guestApps = [];

	/**
	 * A missing deck.read grant refuses the card read before the dispatch runs.
	 *
	 * @return void
	 */
	public function testADeniedCardGrantNeverReachesTheDispatch(): void {
		$this->granted = false;
		$deck = new CountingDeckDispatch();
		$cards = new DeckMeetingCards($this->access(), $deck, new NoAssignments());

		try {
			$cards->card('alice', ['cardId' => 7]);
			self::fail('A denied grant reached the dispatch');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('deck_read_card', $error->getMessage());
		}
		self::assertSame([], $deck->names);
		self::assertSame(0, $deck->writes);
		self::assertSame([['alice', 'deck', ['read']]], $this->asked);
	}

	/**
	 * A Deck app that is off for the actor refuses the link read before the dispatch runs.
	 *
	 * @return void
	 */
	public function testADisabledAppNeverReachesTheDispatch(): void {
		$this->appEnabled = false;
		$deck = new CountingDeckDispatch();
		$cards = new DeckMeetingCards($this->access(), $deck, new NoAssignments());

		try {
			$cards->links('alice', ['cardId' => 7]);
			self::fail('A disabled app reached the dispatch');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('deck_list_card_files', $error->getMessage());
		}
		self::assertSame([], $deck->names);
		self::assertSame(0, $deck->writes);
	}

	/**
	 * A guest whom Guests keeps out of Deck never reaches the dispatch.
	 *
	 * @return void
	 */
	public function testAGuestTheAppForbidsNeverReachesTheDispatch(): void {
		$this->guestAllowed = false;
		$deck = new CountingDeckDispatch();
		$cards = new DeckMeetingCards($this->access(), $deck, new NoAssignments());

		try {
			$cards->card('alice', ['cardId' => 7]);
			self::fail('A forbidden guest reached the dispatch');
		} catch (ToolFailure) {
			self::assertSame([], $deck->names);
			self::assertSame(0, $deck->writes);
		}
		self::assertContains(['alice', 'deck'], $this->guestApps);
	}

	/**
	 * The card and its links dispatch only deck_read_card and deck_list_card_files.
	 *
	 * @return void
	 */
	public function testCardAndLinksDispatchOnlyTheTwoReadTools(): void {
		$deck = new CountingDeckDispatch();
		$cards = new DeckMeetingCards($this->access(), $deck, new NoAssignments());

		$card = $cards->card('alice', ['cardId' => 7]);
		$links = $cards->links('alice', ['cardId' => 7]);

		self::assertSame(ReadCardHandler::TOOL, $card['name']);
		self::assertSame(ListCardFilesHandler::TOOL, $links['name']);
		self::assertSame([ReadCardHandler::TOOL, ListCardFilesHandler::TOOL], $deck->names);
		self::assertSame(0, $deck->writes);
		self::assertSame([
			['alice', 'deck', ['read']],
			['alice', 'deck', ['read']],
		], $this->asked);
		self::assertContains(['alice', 'deck'], $this->guestApps);
	}

	/**
	 * A card id of the wrong type is refused before the dispatch.
	 *
	 * @return void
	 */
	public function testAMistypedCardIdIsRefusedBeforeTheDispatch(): void {
		$deck = new CountingDeckDispatch();
		$cards = new DeckMeetingCards($this->access(), $deck, new NoAssignments());

		try {
			$cards->card('alice', ['cardId' => '7']);
			self::fail('A mistyped card id reached the dispatch');
		} catch (InvalidArgumentException) {
			self::assertSame([], $deck->names);
			self::assertSame(0, $deck->writes);
		}
	}

	/**
	 * A grant that cannot be read propagates and the dispatch is not called.
	 *
	 * @return void
	 */
	public function testAnUnreadableGrantPropagatesAndSkipsTheDispatch(): void {
		$this->unavailable = true;
		$deck = new CountingDeckDispatch();
		$cards = new DeckMeetingCards($this->access(), $deck, new NoAssignments());

		$this->expectException(PolicyUnavailable::class);
		try {
			$cards->links('alice', ['cardId' => 7]);
		} finally {
			self::assertSame([], $deck->names);
			self::assertSame(0, $deck->writes);
		}
	}

	/**
	 * The registry gate of the two Deck reads, with the grant, app and guest answers of this test.
	 *
	 * @return ToolAccess the real registry decision
	 */
	private function access(): ToolAccess {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(function (string $uid, string $module, array $operations): bool {
			$this->asked[] = [$uid, $module, $operations];
			if ($this->unavailable) {
				throw new PolicyUnavailable('x');
			}
			return $this->granted;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (): bool => $this->appEnabled);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (): IUser => $this->createMock(IUser::class));
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturnCallback(function (string $uid, string $app): bool {
			$this->guestApps[] = [$uid, $app];
			return $this->guestAllowed;
		});
		return new ToolAccess($policy, $apps, $users, $guests, new CapabilityRegistry([], new NullLogger()));
	}
}

/** Counts Deck dispatches. A name outside the two reads counts as a write. */
final class CountingDeckDispatch implements DeckReadDispatch {
	public int $writes = 0;
	/** @var list<string> */
	public array $names = [];

	/** {@inheritDoc} */
	public function execute(string $name, array $arguments, string $userId): array {
		$this->names[] = $name;
		if ($name !== ReadCardHandler::TOOL && $name !== ListCardFilesHandler::TOOL) {
			$this->writes++;
		}
		return ['name' => $name, 'arguments' => $arguments, 'userId' => $userId];
	}
}

/** No assigned cards. The read tests do not ask for them. */
final class NoAssignments implements AssignedCardLookup {
	/**
	 * @param string $userId the actor
	 * @param string $participantUid the account
	 * @return list<int> no card ids
	 */
	public function ids(string $userId, string $participantUid): array {
		return [];
	}
}
