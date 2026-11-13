<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Service\BoardService;
use OCA\Mcp\Modules\Deck\Integration\AssignedCards;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceContext;
use OCP\ISession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/** AssignmentMapper::findByParticipant is the only lookup, and it does not apply the board ACL. */
final class AssignedCardsTest extends TestCase {
	/**
	 * User assignments become card ids. A repeated id and an empty id are dropped.
	 *
	 * @return void
	 */
	public function testFindByParticipantReturnsEachPositiveCardIdOnce(): void {
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn('alice');
		$board = $this->createMock(BoardService::class);
		$board->expects(self::once())->method('setUserId')->with('alice');
		$mapper = $this->createMock(AssignmentMapper::class);
		$mapper->expects(self::once())->method('findByParticipant')->with('pedro', Assignment::TYPE_USER)->willReturn([
			new Assignment(['cardId' => 12]),
			new Assignment(['cardId' => 12]),
			new Assignment(['cardId' => 0]),
			new Assignment(['cardId' => 4]),
		]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $class) use ($board, $mapper): object {
			return match ($class) {
				BoardService::class => $board,
				AssignmentMapper::class => $mapper,
				default => throw new \RuntimeException($class),
			};
		});
		$cards = new AssignedCards(new DeckServiceContext($container, $session));
		self::assertSame([12, 4], $cards->ids('alice', 'pedro'));
	}
}
