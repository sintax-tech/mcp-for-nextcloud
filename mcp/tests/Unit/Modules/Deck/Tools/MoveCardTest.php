<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Deck\Tools\MoveCard;
use OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_move_card`.
 */

final class MoveCardTest extends TestCase {
    use DeckToolFixtures;
    /**
     * @param \Throwable|null $failure Exception the capability raises, or null for a happy path.
     * @return MoveCard Handler under test.
     */
    private function handler(?\Throwable $failure = null): MoveCard {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $expectation = $capabilities->writer->method('moveCard');
        if ($failure !== null) {
            $expectation->willThrowException($failure);
        } else {
            $expectation->willReturn($this->card(['id' => 7, 'stackId' => 11]));
        }
        return $this->deckTool(MoveCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
    }

    public function testHappyPathWithoutOrderLetsTheGatewayAppend(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::once())->method('moveCard')->with('alice', 7, 11, null)->willReturn($this->card(['id' => 7, 'stackId' => 11]));
        $handler = $this->deckTool(MoveCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'stackId' => 11], new ToolContext('alice')));
        self::assertSame(11, $payload['stackId']);
    }

    public function testExplicitOrderIsForwardedAsZeroToo(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::once())->method('moveCard')->with('alice', 7, 11, 0)->willReturn($this->card(['id' => 7, 'stackId' => 11]));
        $handler = $this->deckTool(MoveCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $handler->execute(['cardId' => 7, 'stackId' => 11, 'order' => 0], new ToolContext('alice'));
    }

    public function testMovingToAnotherBoardIsTheSameCall(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::once())->method('moveCard')->with('alice', 7, 42, 3)->willReturn($this->card(['id' => 7, 'stackId' => 42]));
        $handler = $this->deckTool(MoveCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        self::assertSame(
            42,
            $this->payload($handler->execute(['cardId' => 7, 'stackId' => 42, 'order' => 3], new ToolContext('alice')))['stackId'],
        );
    }

    public function testDeniedAccessBecomesTheGenericMessage(): void {
        $result = $this->handler(new NoPermissionException('Permission denied'))->execute(['cardId' => 7, 'stackId' => 11], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
        self::assertStringNotContainsString('Permission denied', $this->text($result));
    }

    public function testArchivedCardBecomesTheNotAllowedMessage(): void {
        $result = $this->handler(new StatusException('Operation not allowed. This card is archived.'))->execute(['cardId' => 7, 'stackId' => 11], new ToolContext('alice'));
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotAllowed(), $this->text($result));
    }

    public function testBackendFailureBecomesTheGenericMessage(): void {
        $result = $this->handler(new RuntimeException('deadlock on deck_cards'))->execute(['cardId' => 7, 'stackId' => 11], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
        self::assertStringNotContainsString('deck_cards', $this->text($result));
    }

    public function testSharedOriginBoardWithoutConfirmationMovesNothing(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->expects(self::once())->method('cardOwnership')->with('alice', 7)->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->ownership->expects(self::never())->method('stackOwnership');
        $capabilities->writer->expects(self::never())->method('moveCard');
        $handler = $this->deckTool(MoveCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $this->assertSharedConfirmation($handler->execute(['cardId' => 7, 'stackId' => 11], new ToolContext('alice')));
    }

    public function testSharedDestinationStackAlsoNeedsTheConfirmation(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('alice'));
        $capabilities->ownership->expects(self::once())->method('stackOwnership')->with('alice', 11)->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->writer->expects(self::never())->method('moveCard');
        $handler = $this->deckTool(MoveCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $this->assertSharedConfirmation($handler->execute(['cardId' => 7, 'stackId' => 11], new ToolContext('alice')));
    }

    public function testSharedBoardsWithConfirmationMoveTheCard(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->ownership->method('stackOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->writer->expects(self::once())->method('moveCard')->with('alice', 7, 11, null)->willReturn($this->card(['id' => 7, 'stackId' => 11]));
        $handler = $this->deckTool(MoveCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $payload = $this->payload($handler->execute(['cardId' => 7, 'stackId' => 11, 'confirm_shared' => true], new ToolContext('alice')));
        self::assertSame(11, $payload['stackId']);
    }

    public function testOwnershipDeniedIsRefusedBeforeTheCardMoves(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willThrowException(new NoPermissionException('Permission denied'));
        $capabilities->ownership->expects(self::never())->method('stackOwnership');
        $capabilities->writer->expects(self::never())->method('moveCard');
        $handler = $this->deckTool(MoveCard::class, $capabilities, $this->createMock(LoggerInterface::class), $this->cardFormatter());
        $result = $handler->execute(['cardId' => 7, 'stackId' => 11], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }
}
