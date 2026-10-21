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
use OCA\Mcp\Modules\Deck\Tools\DeleteCard;
use OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_delete_card`, whose schema makes `confirm: true` mandatory before the handler runs.
 */

final class DeleteCardTest extends TestCase {
    use DeckToolFixtures;
    /**
     * @param \Throwable|null $failure Exception the capability raises, or null for a happy path.
     * @return DeleteCard Handler under test.
     */
    private function handler(?\Throwable $failure = null): DeleteCard {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $expectation = $capabilities->writer->method('deleteCard');
        if ($failure !== null) {
            $expectation->willThrowException($failure);
        } else {
            $expectation->willReturn($this->card(['id' => 7, 'deletedAt' => 1700000900]));
        }
        return $this->deckTool(
            DeleteCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
    }

    public function testHappyPathDeletesForTheAuthenticatedUser(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::once())->method('deleteCard')->with('alice', 7)->willReturn($this->card(['id' => 7, 'deletedAt' => 1700000900]));
        $handler = $this->deckTool(
            DeleteCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $payload = $this->payload($handler->execute(['cardId' => 7, 'confirm' => true], new ToolContext('alice')));
        self::assertSame(7, $payload['id']);
    }

    public function testDeleteIsTheDeckSoftDelete(): void {
        // The card is returned with `deletedAt` stamped, exactly as `CardService::delete()` leaves it.
        $payload = $this->payload($this->handler()->execute(['cardId' => 7, 'confirm' => true], new ToolContext('alice')));
        self::assertSame(7, $payload['id']);
    }

    public function testDeniedAccessBecomesTheGenericMessage(): void {
        $result = $this->handler(new NoPermissionException('Permission denied'))->execute(['cardId' => 7, 'confirm' => true], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }

    public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
        $result = $this->handler(new StatusException('Operation not allowed. This board is archived.'))->execute(['cardId' => 7, 'confirm' => true], new ToolContext('alice'));
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotAllowed(), $this->text($result));
    }

    public function testBackendFailureBecomesTheGenericMessage(): void {
        $result = $this->handler(new RuntimeException('deadlock'))->execute(['cardId' => 7, 'confirm' => true], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
    }

    public function testSharedBoardWithoutConfirmationDeletesNothing(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->expects(self::once())->method('cardOwnership')->with('alice', 7)->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->writer->expects(self::never())->method('deleteCard');
        $handler = $this->deckTool(
            DeleteCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $this->assertSharedConfirmation($handler->execute(['cardId' => 7, 'confirm' => true], new ToolContext('alice')));
    }

    public function testSharedBoardWithConfirmationDeletesTheCard(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->writer->expects(self::once())->method('deleteCard')->with('alice', 7)->willReturn($this->card(['id' => 7, 'deletedAt' => 1700000900]));
        $handler = $this->deckTool(
            DeleteCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $payload = $this->payload($handler->execute(['cardId' => 7, 'confirm' => true, 'confirm_shared' => true], new ToolContext('alice')));
        self::assertSame(7, $payload['id']);
    }

    public function testOwnershipDeniedIsRefusedBeforeTheCardIsDeleted(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('cardOwnership')->willThrowException(new NoPermissionException('Permission denied'));
        $capabilities->writer->expects(self::never())->method('deleteCard');
        $handler = $this->deckTool(
            DeleteCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $result = $handler->execute(['cardId' => 7, 'confirm' => true], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }
}
