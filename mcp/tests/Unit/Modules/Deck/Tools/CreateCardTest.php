<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use InvalidArgumentException;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use OCA\Mcp\Modules\Deck\Cards\CardInput;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Deck\Tools\CreateCard;
use OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_create_card`.
 */

final class CreateCardTest extends TestCase {
    use DeckToolFixtures;
    /**
     * @param \Throwable|null $failure Exception the capability raises, or null for a happy path.
     * @return CreateCard Handler under test.
     */
    private function handler(?\Throwable $failure = null): CreateCard {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $expectation = $capabilities->writer->method('createCard');
        if ($failure !== null) {
            $expectation->willThrowException($failure);
        } else {
            $expectation->willReturn($this->card(['id' => 9, 'title' => 'Fechar contrato']));
        }
        return $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
    }

    /** A rejected assignee stops the creation before any write. */
    public function testInvalidAssigneeCreatesNothing(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->assignments->expects(self::once())->method('validateAssignees')->with('alice', 10, ['missing'])->willThrowException(new InvalidArgumentException('Invalid argument: assignees'));
        $capabilities->writer->expects(self::never())->method('createCard');
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $this->expectException(InvalidArgumentException::class);
        $handler->execute(['stackId' => 10, 'title' => 'New', 'assignees' => ['missing']], new ToolContext('alice'));
    }

    /** A race after creation returns the created card with a safe warning instead of an error or deletion. */
    public function testAssignmentRaceKeepsCreatedCard(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->assignments->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->writer->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
        $capabilities->assignments->expects(self::once())->method('assignCardUser')->with('alice', 9, 'pedro')->willThrowException(new RuntimeException('secret'));
        $capabilities->writer->expects(self::never())->method('deleteCard');
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $result = $handler->execute(['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro']], new ToolContext('alice'));
        $payload = $this->payload($result);
        self::assertSame(9, $payload['id']);
        self::assertSame(
            ['The card was created, but one or more assignees could not be assigned. Read the card before retrying assignment in Deck.'],
            $payload['warnings'],
        );
        self::assertArrayNotHasKey('isError', $result);
        self::assertStringNotContainsString('secret', json_encode($result));
    }

    /** Successful assignments are included in the created card returned to the caller. */
    public function testCreatedCardIncludesSuccessfulAssignments(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->assignments->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro'])->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->writer->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
        $capabilities->assignments->expects(self::once())->method('assignCardUser')->with('alice', 9, 'pedro')->willReturn(new \OCA\Deck\Db\Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => 0]));
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $payload = $this->payload($handler->execute(['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro']], new ToolContext('alice')));
        self::assertSame('pedro', $payload['assignedUsers'][0]['uid']);
        self::assertArrayNotHasKey('warnings', $payload);
    }

    /** Only the accounts the validator returned are assigned, whatever the client asked for. */
    public function testOnlyTheValidatedAssigneesAreAssigned(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->assignments->expects(self::once())->method('validateAssignees')->with('alice', 10, ['pedro', 'intruso'])->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->writer->expects(self::once())->method('createCard')->willReturn($this->card(['id' => 9]));
        $capabilities->assignments->expects(self::once())->method('assignCardUser')->with('alice', 9, 'pedro')->willReturn(new \OCA\Deck\Db\Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => 0]));
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $payload = $this->payload($handler->execute(['stackId' => 10, 'title' => 'New', 'assignees' => ['pedro', 'intruso']], new ToolContext('alice')));
        self::assertSame(['pedro'], array_column($payload['assignedUsers'], 'uid'));
    }

    public function testHappyPathSendsTheCardOwnedByTheCaller(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::once())->method('createCard')->with('alice', 10, 'Fechar contrato', 'Com o cliente', '2026-03-01')->willReturn($this->card(['id' => 9]));
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $payload = $this->payload($handler->execute(
            [
                'stackId' => 10,
                'title' => '  Fechar contrato  ',
                'description' => 'Com o cliente',
                'duedate' => '2026-03-01',
            ],
            new ToolContext('alice'),
        ));
        self::assertSame(9, $payload['id']);
    }

    public function testOptionalFieldsFallBackToEmptyAndNoDate(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::once())->method('createCard')->with('alice', 10, 'Fechar', '', null)->willReturn($this->card(['id' => 9]));
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $handler->execute(['stackId' => 10, 'title' => 'Fechar'], new ToolContext('alice'));
    }

    public function testBlankTitleIsAParameterErrorAndNeverReachesDeck(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::never())->method('createCard');
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorTitleRequired());
        $handler->execute(['stackId' => 10, 'title' => '   '], new ToolContext('alice'));
    }

    public function testImpossibleDateIsAParameterError(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::never())->method('createCard');
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorInvalidDuedate());
        $handler->execute(['stackId' => 10, 'title' => 'Fechar', 'duedate' => '2026-02-31'], new ToolContext('alice'));
    }

    public function testOversizedDescriptionIsRefusedInsteadOfTruncated(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->expects(self::never())->method('createCard');
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorDescriptionTooLong());
        $handler->execute(
            [
                'stackId' => 10,
                'title' => 'Fechar',
                'description' => str_repeat('a', CardInput::MAX_DESCRIPTION_LENGTH + 1),
            ],
            new ToolContext('alice'),
        );
    }

    public function testDeniedAccessBecomesTheGenericMessage(): void {
        $result = $this->handler(new NoPermissionException('Permission denied'))->execute(['stackId' => 10, 'title' => 'Fechar'], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }

    public function testArchivedBoardBecomesTheNotAllowedMessage(): void {
        $result = $this->handler(new StatusException('Operation not allowed. This board is archived.'))->execute(['stackId' => 10, 'title' => 'Fechar'], new ToolContext('alice'));
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotAllowed(), $this->text($result));
    }

    public function testBackendFailureBecomesTheGenericMessage(): void {
        $result = $this->handler(new RuntimeException('foreign key violation'))->execute(['stackId' => 10, 'title' => 'Fechar'], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
        self::assertStringNotContainsString('foreign key', $this->text($result));
    }

    public function testSharedBoardWithoutConfirmationCreatesNothing(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->expects(self::once())->method('stackOwnership')->with('alice', 10)->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->writer->expects(self::never())->method('createCard');
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $this->assertSharedConfirmation($handler->execute(['stackId' => 10, 'title' => 'Fechar contrato'], new ToolContext('alice')));
    }

    public function testSharedBoardWithConfirmationCreatesTheCard(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('stackOwnership')->willReturn($this->ownershipOf('pedro', 'Comercial'));
        $capabilities->writer->expects(self::once())->method('createCard')->with('alice', 10, 'Fechar contrato', '', null)->willReturn($this->card(['id' => 9]));
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $payload = $this->payload($handler->execute(['stackId' => 10, 'title' => 'Fechar contrato', 'confirm_shared' => true], new ToolContext('alice')));
        self::assertSame(9, $payload['id']);
    }

    public function testOwnershipDeniedIsRefusedBeforeAnythingIsCreated(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('stackOwnership')->willThrowException(new NoPermissionException('Permission denied'));
        $capabilities->writer->expects(self::never())->method('createCard');
        $handler = $this->deckTool(
            CreateCard::class,
            $capabilities,
            $this->createMock(LoggerInterface::class),
            $this->cardFormatter(),
        );
        $result = $handler->execute(['stackId' => 10, 'title' => 'Fechar contrato'], new ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }
}
