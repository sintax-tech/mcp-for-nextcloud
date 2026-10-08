<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\Handler\FollowupCardsHandler;
use OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Stubs/deck_stubs.php';

/**
 * Covers `deck_followup_cards`: what the handler validates, and how the answer is grouped.
 *
 * The gateway decides which cards belong to the follow-up, so the gateway double here answers a
 * fixed page and the assertions stay on the contract of the tool.
 */
final class FollowupCardsHandlerTest extends TestCase {
	use DeckTestHelpers;

	/**
	 * @param list<array{card: Card, boardId: int}> $items Cards the gateway returns.
	 * @param bool $truncated Whether the gateway reports an incomplete answer.
	 * @param LoggerInterface|null $logger Logger to inspect.
	 * @return FollowupCardsHandler Handler under test.
	 */
	private function handler(array $items = [], bool $truncated = false, ?LoggerInterface $logger = null): FollowupCardsHandler {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('followupCards')->willReturn(['items' => $items, 'truncated' => $truncated]);

		return new FollowupCardsHandler($gateway, $logger ?? $this->createMock(LoggerInterface::class), $this->cardFormatter());
	}

	/**
	 * @param Card $card Card of the page.
	 * @param int $boardId Board the card came from.
	 * @return array{card: Card, boardId: int} One entry of the gateway page.
	 */
	private function item(Card $card, int $boardId = 4): array {
		return ['card' => $card, 'boardId' => $boardId];
	}

	/**
	 * Card of a board, responsible for one person, ready to be put in a page.
	 *
	 * @param string $uid UID of the person responsible for the card.
	 * @param int $cardId Card id.
	 * @param int $boardId Board the card came from.
	 * @return Card Card assigned to that person.
	 */
	private function assignedCard(string $uid, int $cardId, int $boardId = 4): Card {
		return $this->card([
			'id' => $cardId,
			'title' => 'Card ' . $cardId,
			'relatedBoard' => new Board(['id' => $boardId]),
			'assignedUsers' => [
				new Assignment(['cardId' => $cardId, 'participant' => $uid, 'type' => Acl::PERMISSION_TYPE_USER]),
			],
		]);
	}

	/**
	 * @param array{groups: list<array<string, mixed>>} $payload Decoded payload of the tool.
	 * @return list<string|null> UID of each group, null for the group of the unassigned cards.
	 */
	private function assigneeUids(array $payload): array {
		return array_map(
			static fn (array $group): ?string => $group['assignee'] === null ? null : $group['assignee']['uid'],
			$payload['groups'],
		);
	}

	public function testGroupsTheAnswerByResponsiblePerson(): void {
		$handler = $this->handler([
			$this->item($this->assignedCard('pedro', 1)),
			$this->item($this->assignedCard('alice', 2)),
		]);

		$payload = $this->payload($handler->handle([], 'alice'));

		self::assertSame(['alice', 'pedro'], $this->assigneeUids($payload));
		self::assertSame([2], array_column($payload['groups'][0]['cards'], 'id'));
		self::assertSame([1], array_column($payload['groups'][1]['cards'], 'id'));
		self::assertFalse($payload['truncated']);
	}

	public function testAnswersEveryCardWithItsLinkAndWhetherItIsLate(): void {
		$handler = $this->handler([$this->item($this->assignedCard('alice', 7))]);

		$payload = $this->payload($handler->handle([], 'alice'));
		$card = $payload['groups'][0]['cards'][0];

		self::assertSame(4, $card['boardId']);
		self::assertSame([['uid' => 'alice', 'displayName' => 'alice']], $card['assignedUsers']);
		self::assertFalse($card['overdue']);
		self::assertIsString($card['url']);
	}

	public function testPutsCardsNobodyIsAssignedToInAGroupWithoutAName(): void {
		$handler = $this->handler([$this->item($this->card(['id' => 5]))]);

		$payload = $this->payload($handler->handle([], 'alice'));

		self::assertCount(1, $payload['groups']);
		self::assertNull($payload['groups'][0]['assignee']);
		self::assertSame([5], array_column($payload['groups'][0]['cards'], 'id'));
	}

	public function testShowsACardInEveryGroupOfItsResponsiblePeople(): void {
		$card = $this->card([
			'id' => 7,
			'assignedUsers' => [
				new Assignment(['cardId' => 7, 'participant' => 'alice', 'type' => Acl::PERMISSION_TYPE_USER]),
				new Assignment(['cardId' => 7, 'participant' => 'pedro', 'type' => Acl::PERMISSION_TYPE_USER]),
			],
		]);
		$handler = $this->handler([$this->item($card)]);

		$payload = $this->payload($handler->handle([], 'alice'));

		self::assertSame(['alice', 'pedro'], $this->assigneeUids($payload));
		foreach ($payload['groups'] as $group) {
			self::assertSame([7], array_column($group['cards'], 'id'));
		}
	}

	public function testSortsTheGroupsByUidAndLeadsWithTheCardsNobodyOwns(): void {
		$handler = $this->handler([
			$this->item($this->card(['id' => 1])),
			$this->item($this->assignedCard('zeca', 2)),
			$this->item($this->assignedCard('alice', 3)),
		]);

		$payload = $this->payload($handler->handle([], 'alice'));

		self::assertSame([null, 'alice', 'zeca'], $this->assigneeUids($payload));
	}

	public function testForwardsTheDefaultFilterWhenTheCallerSendsNothing(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('followupCards')
			->with('alice', 'overdue', null, null, null, FollowupCardsHandler::DEFAULT_LIMIT)
			->willReturn(['items' => [], 'truncated' => false]);
		$handler = new FollowupCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle([], 'alice'));

		self::assertSame(['groups' => [], 'truncated' => false], $payload);
	}

	public function testForwardsEveryFilterTheCallerNames(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('followupCards')
			->with('alice', 'open', 12, 'pedro', '2026-03-31', 25)
			->willReturn(['items' => [], 'truncated' => true]);
		$handler = new FollowupCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$payload = $this->payload($handler->handle([
			'status' => 'open',
			'boardId' => 12,
			'assignee' => 'pedro',
			'dueBefore' => '2026-03-31',
			'limit' => 25,
		], 'alice'));

		self::assertTrue($payload['truncated']);
	}

	public function testRefusesAStatusTheDeckDoesNotGroupBy(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::never())->method('followupCards');
		$handler = new FollowupCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorInvalidStatus());

		$handler->handle(['status' => 'late'], 'alice');
	}

	public function testRefusesADueBeforeThatIsNotACalendarDate(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::never())->method('followupCards');
		$handler = new FollowupCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(DeckMessages::errorInvalidDueBefore());

		$handler->handle(['dueBefore' => '31/03/2026'], 'alice');
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function limitProvider(): array {
		return [
			'below the floor is raised' => [0, 1],
			'inside the bounds is kept' => [250, 250],
			'above the ceiling is lowered' => [9999, FollowupCardsHandler::MAX_LIMIT],
		];
	}

	/**
	 * @param int $limit Limit sent by the client.
	 * @param int $expected Limit the gateway receives.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('limitProvider')]
	public function testKeepsTheLimitInsideTheDocumentedBounds(int $limit, int $expected): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->expects(self::once())
			->method('followupCards')
			->with('alice', 'overdue', null, null, null, $expected)
			->willReturn(['items' => [], 'truncated' => false]);
		$handler = new FollowupCardsHandler($gateway, $this->createMock(LoggerInterface::class), $this->cardFormatter());

		$this->payload($handler->handle(['limit' => $limit], 'alice'));
	}

	public function testBackendFailureIsLoggedByClassOnly(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				self::stringContains('{exception}'),
				self::callback(static function (array $context): bool {
					return $context['tool'] === 'deck_followup_cards'
						&& $context['exception'] === RuntimeException::class
						&& !str_contains(json_encode($context), 'connection refused');
				}),
			);
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('followupCards')->willThrowException(new RuntimeException('connection refused'));
		$handler = new FollowupCardsHandler($gateway, $logger, $this->cardFormatter());

		$result = $handler->handle([], 'alice');

		self::assertTrue($result['isError']);
		self::assertSame(DeckMessages::errorGeneric(), $this->text($result));
	}
}
