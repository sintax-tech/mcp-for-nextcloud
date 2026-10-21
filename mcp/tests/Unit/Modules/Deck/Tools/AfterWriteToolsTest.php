<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckConflictException;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCA\Mcp\Modules\Deck\Execution\DeckUnconfirmedException;
use OCA\Mcp\Modules\Deck\Tools\CreateBoard;
use OCA\Mcp\Modules\Deck\Tools\CreateCard;
use OCA\Mcp\Modules\Deck\Tools\CreateStack;
use OCA\Mcp\Modules\Deck\Tools\DeleteBoard;
use OCA\Mcp\Modules\Deck\Tools\DeleteCard;
use OCA\Mcp\Modules\Deck\Tools\DeleteStack;
use OCA\Mcp\Modules\Deck\Tools\EditCard;
use OCA\Mcp\Modules\Deck\Tools\MoveCard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Re-review of c06b2e0: Deck 1.17.5 writes first and only then runs activity, events and notifications, any of which
 * may throw. A write tool never answers with an error once the write may exist: after an exception of the write call
 * itself the handler reads the real state again. Written: success with a warning. Not written: the usual error.
 * Unknown: a result that is not an error and says to read before trying again.
 *
 * Every stub here throws after "writing", the way a failing listener would, and its message is a secret that must
 * never reach the answer or the log.
 */

final class AfterWriteToolsTest extends TestCase {
    use DeckToolFixtures;
    private const SECRET = 'listener failed for /secret/path';
    /** @var list<array{string, array<string, mixed>}> Log lines of the handlers, level-independent. */
    private array $logged = [];
    private LoggerInterface&MockObject $logger;
    protected function setUp(): void {
        $this->logger = $this->createMock(LoggerInterface::class);
        foreach (['warning', 'error'] as $level) {
            $this->logger->method($level)->willReturnCallback(function (string $message, array $context = []): void {
                $this->logged[] = [$message, $context];
            });
        }
    }

    protected function tearDown(): void {
        foreach ($this->logged as [$message, $context]) {
            self::assertStringNotContainsString(self::SECRET, $message . json_encode($context), 'o log leva só a classe');
        }
    }

    private function afterWrite(): \RuntimeException {
        return new \RuntimeException(self::SECRET);
    }

    /** @param array<string, mixed> $result */
    private function assertWrittenWithWarning(array $result): array {
        self::assertArrayNotHasKey('isError', $result);
        self::assertStringNotContainsString(self::SECRET, $this->text($result));
        $payload = $this->payload($result);
        self::assertContains(\OCA\Mcp\Modules\Deck\Execution\WriteMessages::writtenThenFailed(), $payload['warnings']);
        self::assertContains(\RuntimeException::class, array_column(array_column($this->logged, 1), 'exception'));
        return $payload;
    }

    /** @param array<string, mixed> $result */
    private function assertUnconfirmed(array $result, string $readWith): void {
        self::assertArrayNotHasKey('isError', $result, 'nunca um erro que convide a repetir');
        self::assertSame(
            [
                'confirmed' => false,
                'message' => \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writeUnconfirmed($readWith),
            ],
            $this->payload($result),
        );
    }

    /** @param array<string, mixed> $result */
    private function assertPlainError(array $result): void {
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
    }

    private function cards(DeckDoubles $capabilities): array {
        $formatter = $this->cardFormatter();
        return [
            'create' => $this->deckTool(CreateCard::class, $capabilities, $this->logger, $formatter),
            'edit' => $this->deckTool(EditCard::class, $capabilities, $this->logger, $formatter),
            'move' => $this->deckTool(MoveCard::class, $capabilities, $this->logger, $formatter),
            'delete' => $this->deckTool(DeleteCard::class, $capabilities, $this->logger, $formatter),
        ];
    }
    // --- deck_create_card ---------------------------------------------------------------------------------------
    public function testACardFoundAfterAListenerFailedIsProbable(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('createCard')->willThrowException($this->afterWrite());
        $capabilities->state->expects(self::once())->method('findCreatedCard')->with('alice', 10, 'Fechar', [])->willReturn($this->card(['id' => 77, 'title' => 'Fechar']));
        // The card is found only by owner, title and time, so the answer is "probable", never "saved".
        $payload = $this->assertProbable($this->cards($capabilities)['create']->execute(['stackId' => 10, 'title' => 'Fechar'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(77, $payload['id']);
    }

    public function testACardThatIsNotThereIsTheUsualError(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('createCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('findCreatedCard')->willReturn(null);
        $this->assertPlainError($this->cards($capabilities)['create']->execute(['stackId' => 10, 'title' => 'Fechar'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
    }

    public function testACardThatCannotBeCheckedIsUnconfirmedAndNotAnError(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('createCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('findCreatedCard')->willThrowException(new \RuntimeException('db gone'));
        $this->assertUnconfirmed(
            $this->cards($capabilities)['create']->execute(['stackId' => 10, 'title' => 'Fechar'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_list_cards',
        );
    }

    /** The refusals of this app, raised before Deck is called, stay errors and are not even checked. */
    public function testARefusalOfTheAppBeforeTheWriteIsNeverChecked(): void {
        foreach ([
            DeckRefusalException::boardNotOwned(),
            new DeckSessionException('session'),
            new DeckConflictException('conflict'),
        ] as $refusal) {
            $capabilities = $this->deckDoublesOwnedBy('alice');
            $capabilities->writer->method('createCard')->willThrowException($refusal);
            $capabilities->state->expects(self::never())->method('findCreatedCard');
            $result = $this->cards($capabilities)['create']->execute(['stackId' => 10, 'title' => 'Fechar'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
            self::assertTrue($result['isError']);
        }
    }

    /**
     * Third round: a Deck listener may throw the very classes Deck uses for its own checks, after the card is saved.
     * The class alone proves nothing, so the state is read again.
     */
    public function testADeckExceptionRaisedByAListenerAfterTheWriteIsStillReadBack(): void {
        foreach ([new NoPermissionException('Permission denied'), new BadRequestException('listener says no')] as $thrown) {
            $capabilities = $this->deckDoublesOwnedBy('alice');
            $capabilities->cards->method('findCard')->willReturn($this->card(['id' => 7, 'title' => 'Antigo', 'lastModified' => 1700000000]));
            $capabilities->writer->method('updateCard')->willThrowException($thrown);
            $capabilities->state->expects(self::once())->method('cardState')->with('alice', 7)->willReturn($this->card(['id' => 7, 'title' => 'Novo', 'lastModified' => 1700000100]));
            $result = $this->cards($capabilities)['edit']->execute(['cardId' => 7, 'title' => 'Novo'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
            self::assertArrayNotHasKey('isError', $result);
            self::assertContains(
                \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writtenThenFailed(),
                $this->payload($result)['warnings'],
            );
        }
    }

    /**
     * A listener of another app may throw a plain `\InvalidArgumentException` after Deck saved the card; only the
     * exclusive `ArgumentValidationException` of this app is a refusal that skips the read-back.
     */
    public function testAPlainInvalidArgumentExceptionFromAListenerAfterTheWriteIsReadBack(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('createCard')->willThrowException(new \InvalidArgumentException('listener says no'));
        $capabilities->state->expects(self::once())->method('findCreatedCard')->willReturn($this->card(['id' => 77, 'title' => 'Fechar']));
        $payload = $this->assertProbable($this->cards($capabilities)['create']->execute(['stackId' => 10, 'title' => 'Fechar'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(77, $payload['id']);
        $edit = $this->deckDoublesOwnedBy('alice');
        $edit->cards->method('findCard')->willReturn($this->card(['id' => 7, 'title' => 'Antigo', 'lastModified' => 1700000000]));
        $edit->writer->method('updateCard')->willThrowException(new \InvalidArgumentException('listener says no'));
        $edit->state->expects(self::once())->method('cardState')->willReturn($this->card(['id' => 7, 'title' => 'Novo', 'lastModified' => 1700000100]));
        $result = $this->cards($edit)['edit']->execute(['cardId' => 7, 'title' => 'Novo'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertArrayNotHasKey('isError', $result);
        self::assertContains(
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writtenThenFailed(),
            $this->payload($result)['warnings'],
        );
    }

    /** The validation exception of this app is a refusal before the write: never read back. */
    public function testAnArgumentValidationExceptionOfTheAppIsNeverReadBack(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('createCard')->willThrowException(new ArgumentValidationException('bad', 'title', 'required'));
        $capabilities->state->expects(self::never())->method('findCreatedCard');
        $this->expectException(ArgumentValidationException::class);
        $this->cards($capabilities)['create']->execute(['stackId' => 10, 'title' => 'Fechar'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
    }

    /** A real refusal of Deck, read back, finds nothing: the usual error. */
    public function testARealDeckRefusalReadBackAsNotWrittenIsTheUsualError(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('createCard')->willThrowException(new NoPermissionException('Permission denied'));
        $capabilities->state->expects(self::once())->method('findCreatedCard')->willReturn(null);
        self::assertTrue($this->cards($capabilities)['create']->execute(['stackId' => 10, 'title' => 'Fechar'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'))['isError']);
    }

    public function testAnAssignmentSavedBeforeTheListenerFailedIsKept(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->assignments->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->writer->method('createCard')->willReturn($this->card(['id' => 9]));
        $capabilities->assignments->method('assignCardUser')->willThrowException($this->afterWrite());
        $capabilities->assignments->expects(self::once())->method('cardAssignments')->with('alice', 9)->willReturn([new Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => 0])]);
        $payload = $this->assertWrittenWithWarning($this->cards($capabilities)['create']->execute(
            ['stackId' => 10, 'title' => 'X', 'assignees' => ['pedro']],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(['pedro'], array_column($payload['assignedUsers'], 'uid'));
        self::assertCount(1, $payload['warnings'], 'sem o aviso de atribuição que falhou');
    }
    // --- deck_edit_card -----------------------------------------------------------------------------------------
    private function editGateway(): DeckDoubles {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->card(['id' => 7, 'title' => 'Antigo', 'lastModified' => 1700000000]));
        $capabilities->writer->method('updateCard')->willThrowException($this->afterWrite());
        return $capabilities;
    }

    public function testAnEditReadBackWithTheNewFieldsIsASuccessWithAWarning(): void {
        $capabilities = $this->editGateway();
        $capabilities->state->expects(self::once())->method('cardState')->with('alice', 7)->willReturn($this->card(['id' => 7, 'title' => 'Novo', 'lastModified' => 1700000100]));
        $payload = $this->assertWrittenWithWarning($this->cards($capabilities)['edit']->execute(['cardId' => 7, 'title' => 'Novo'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame('Novo', $payload['title']);
    }

    /** A database without 4-byte UTF-8 stores an emoji as U+FFFD: the card it read back is still the one just written. */
    public function testAnEditWithAnEmojiReadBackWithItsReplacementCharacterIsASuccessWithAWarning(): void {
        $capabilities = $this->editGateway();
        $capabilities->state->expects(self::once())->method('cardState')->with('alice', 7)->willReturn($this->card(['id' => 7, 'title' => 'Novo', 'description' => "Feito �", 'lastModified' => 1700000100]));
        $payload = $this->assertWrittenWithWarning($this->cards($capabilities)['edit']->execute(
            ['cardId' => 7, 'title' => 'Novo', 'description' => "Feito 🚀"],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame('Novo', $payload['title']);
    }

    public function testAnEditReadBackUnchangedIsTheUsualError(): void {
        $capabilities = $this->editGateway();
        $capabilities->state->method('cardState')->willReturn($this->card(['id' => 7, 'title' => 'Antigo', 'lastModified' => 1700000000]));
        $this->assertPlainError($this->cards($capabilities)['edit']->execute(['cardId' => 7, 'title' => 'Novo'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
    }

    /** Asking for the value the card already has proves nothing: without a new modification time it was not written. */
    public function testAnEditToTheSameValueIsNotTakenForAWrite(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->card(['id' => 7, 'title' => 'Igual', 'lastModified' => 1700000000]));
        $capabilities->writer->method('updateCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('cardState')->willReturn($this->card(['id' => 7, 'title' => 'Igual', 'lastModified' => 1700000000]));
        $this->assertPlainError($this->cards($capabilities)['edit']->execute(['cardId' => 7, 'title' => 'Igual'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
    }

    public function testAnEditThatCannotBeCheckedIsUnconfirmed(): void {
        $capabilities = $this->editGateway();
        $capabilities->state->method('cardState')->willThrowException(new \RuntimeException('db gone'));
        $this->assertUnconfirmed(
            $this->cards($capabilities)['edit']->execute(['cardId' => 7, 'title' => 'Novo'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_read_card',
        );
    }

    public function testAssignAndUnassignSavedBeforeTheListenerFailedAreReportedAsDone(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->card(['id' => 7, 'assignedUsers' => [new Assignment(['cardId' => 7, 'participant' => 'bob', 'type' => 0])]]));
        $capabilities->assignments->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->assignments->method('assignCardUser')->willThrowException($this->afterWrite());
        $capabilities->assignments->method('unassignCardUser')->willThrowException($this->afterWrite());
        $capabilities->assignments->method('cardAssignments')->willReturn([new Assignment(['cardId' => 7, 'participant' => 'pedro', 'type' => 0])]);
        $payload = $this->assertWrittenWithWarning($this->cards($capabilities)['edit']->execute(
            ['cardId' => 7, 'assign' => ['pedro'], 'unassign' => ['bob']],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(['pedro'], $payload['assigned']);
        self::assertSame(['bob'], $payload['unassigned']);
        self::assertSame([], $payload['failed']);
    }

    public function testAnAssignmentThatCannotBeCheckedFailsWithTheReadHint(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->cards->method('findCard')->willReturn($this->card(['id' => 7]));
        $capabilities->assignments->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->assignments->method('assignCardUser')->willThrowException($this->afterWrite());
        $capabilities->assignments->method('cardAssignments')->willThrowException(new \RuntimeException('db gone'));
        $payload = $this->payload($this->cards($capabilities)['edit']->execute(['cardId' => 7, 'assign' => ['pedro']], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(
            [[
                'uid' => 'pedro',
                'reason' => \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writeUnconfirmed('deck_read_card'),
            ]],
            $payload['failed'],
        );
    }
    // --- deck_move_card -----------------------------------------------------------------------------------------
    public function testAMoveReadBackInTheDestinationIsASuccessWithAWarning(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('moveCard')->willThrowException($this->afterWrite());
        $capabilities->state->expects(self::exactly(2))->method('cardState')->with('alice', 7)->willReturnOnConsecutiveCalls($this->card(['id' => 7, 'stackId' => 10]), $this->card(['id' => 7, 'stackId' => 11]));
        $payload = $this->assertWrittenWithWarning($this->cards($capabilities)['move']->execute(['cardId' => 7, 'stackId' => 11], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(11, $payload['stackId']);
    }

    public function testAMoveReadBackInTheOriginIsTheUsualError(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('moveCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('cardState')->willReturn($this->card(['id' => 7, 'stackId' => 10]));
        $this->assertPlainError($this->cards($capabilities)['move']->execute(['cardId' => 7, 'stackId' => 11], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
    }

    public function testAMoveThatCannotBeCheckedIsUnconfirmed(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('moveCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('cardState')->willReturnOnConsecutiveCalls($this->card(['id' => 7, 'stackId' => 10]), self::throwException(new \RuntimeException('db gone')));
        $this->assertUnconfirmed(
            $this->cards($capabilities)['move']->execute(['cardId' => 7, 'stackId' => 11], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_read_card',
        );
    }
    // --- deck_delete_card ---------------------------------------------------------------------------------------
    public function testADeletionReadBackAsDeletedIsASuccessWithAWarning(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('deleteCard')->willThrowException($this->afterWrite());
        $capabilities->state->expects(self::once())->method('cardState')->with('alice', 7)->willReturn($this->card(['id' => 7, 'deletedAt' => 1700000500]));
        $payload = $this->assertWrittenWithWarning($this->cards($capabilities)['delete']->execute(['cardId' => 7, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(7, $payload['id']);
    }

    public function testADeletionReadBackAliveIsTheUsualError(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('deleteCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('cardState')->willReturn($this->card(['id' => 7, 'deletedAt' => 0]));
        $this->assertPlainError($this->cards($capabilities)['delete']->execute(['cardId' => 7, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
    }

    public function testADeletionThatCannotBeCheckedIsUnconfirmed(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->writer->method('deleteCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('cardState')->willThrowException(new \RuntimeException('db gone'));
        $this->assertUnconfirmed(
            $this->cards($capabilities)['delete']->execute(['cardId' => 7, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_read_card',
        );
    }
    // --- deck_create_stack, deck_delete_stack, deck_delete_board ------------------------------------------------
    private function structureGateway(): DeckDoubles {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('boardOwnership')->willReturn($this->ownershipOf('alice', 'Comercial'));
        $capabilities->ownership->method('stackOwnership')->willReturn($this->ownershipOf('alice', 'Comercial'));
        return $capabilities;
    }

    public function testAListFoundAfterAnEventFailedIsProbable(): void {
        $capabilities = $this->structureGateway();
        $capabilities->stackCreator->method('createStack')->willThrowException($this->afterWrite());
        $capabilities->structureState->expects(self::exactly(2))->method('stacksOf')->with('alice', 4)->willReturnOnConsecutiveCalls(
            [new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Revisão'])],
            [
                new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Revisão']),
                new Stack(['id' => 12, 'boardId' => 4, 'title' => 'Revisão', 'order' => 1]),
            ],
        );
        $payload = $this->assertProbable($this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(
            ['boardId' => 4, 'title' => 'Revisão', 'confirm' => true],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(12, $payload['id'], 'a lista que já existia com o mesmo título não conta');
    }

    public function testAListThatIsNotThereIsTheUsualError(): void {
        $capabilities = $this->structureGateway();
        $capabilities->stackCreator->method('createStack')->willThrowException($this->afterWrite());
        $capabilities->structureState->method('stacksOf')->willReturn([new Stack(['id' => 10, 'title' => 'Revisão'])]);
        $this->assertPlainError($this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(
            ['boardId' => 4, 'title' => 'Revisão', 'confirm' => true],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
    }

    public function testAListThatCannotBeCheckedIsUnconfirmed(): void {
        $capabilities = $this->structureGateway();
        $capabilities->stackCreator->method('createStack')->willThrowException($this->afterWrite());
        $capabilities->structureState->method('stacksOf')->willReturnOnConsecutiveCalls([], self::throwException(new \RuntimeException('db gone')));
        $this->assertUnconfirmed(
            $this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(
                ['boardId' => 4, 'title' => 'Revisão', 'confirm' => true],
                new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
            ),
            'deck_list_stacks',
        );
    }

    public function testAListDeletionReadBackAsDeletedIsASuccessWithAWarning(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyStack')->willThrowException($this->afterWrite());
        $capabilities->structureState->expects(self::once())->method('deletedStack')->with('alice', 10)->willReturn(new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Velha']));
        $payload = $this->assertWrittenWithWarning($this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(
            ['id' => 10, 'boardId' => 4, 'title' => 'Velha', 'deleted' => true],
            array_diff_key($payload, ['warnings' => 1]),
        );
    }

    public function testAListDeletionThatCannotBeCheckedIsUnconfirmed(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyStack')->willThrowException($this->afterWrite());
        $capabilities->structureState->method('deletedStack')->willThrowException(new \RuntimeException('db gone'));
        $this->assertUnconfirmed(
            $this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_list_stacks',
        );
    }

    /** The refusals of the capability (cards in the list, not the owner) are decided before any write. */
    public function testTheRefusalsOfTheDeletionsAreNeverChecked(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyStack')->willThrowException(DeckRefusalException::stackNotEmpty(2));
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardNotOwned());
        $capabilities->structureState->expects(self::never())->method('deletedStack');
        $capabilities->structureState->expects(self::never())->method('ownedBoards');
        self::assertTrue($this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'))['isError']);
        self::assertTrue($this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'))['isError']);
    }

    /** A card arrived during the deletion and the delete was taken back: a plain refusal, nothing was deleted. */
    public function testADeletionTakenBackIsARefusal(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyStack')->willThrowException(DeckRefusalException::stackReceivedCards(true));
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardReceivedCards(true));
        $stack = $this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        $board = $this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($stack['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorStackReceivedCards(true), $this->text($stack));
        self::assertTrue($board['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorBoardReceivedCards(true), $this->text($board));
    }

    /** The undo failed and the list or board sits in the Deck trash with the new card: partial, never an error. */
    public function testADeletionThatCouldNotBeTakenBackIsPartialAndNotAnError(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyStack')->willThrowException(DeckRefusalException::stackReceivedCards(false));
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardReceivedCards(false));
        $capabilities->structureState->expects(self::never())->method('deletedStack');
        $capabilities->structureState->expects(self::never())->method('ownedBoards');
        $stack = $this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        $board = $this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertArrayNotHasKey('isError', $stack);
        self::assertSame(
            [
                'partial' => true,
                'message' => \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorStackReceivedCards(false),
            ],
            $this->payload($stack),
        );
        self::assertArrayNotHasKey('isError', $board);
        self::assertSame(
            [
                'partial' => true,
                'message' => \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorBoardReceivedCards(false),
            ],
            $this->payload($board),
        );
    }

    /** The capability already read the list again and could not tell: the handler does not read it a second time. */
    public function testAnUnconfirmedRestoreOfTheGatewayIsNotReadBackAgain(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyStack')->willThrowException(new DeckUnconfirmedException('deck_list_stacks', $this->afterWrite()));
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException(new DeckUnconfirmedException('deck_list_boards', $this->afterWrite()));
        $capabilities->structureState->expects(self::never())->method('deletedStack');
        $capabilities->structureState->expects(self::never())->method('ownedBoards');
        $this->assertUnconfirmed(
            $this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_list_stacks',
        );
        $this->assertUnconfirmed(
            $this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_list_boards',
        );
    }

    public function testABoardDeletionReadBackAsDeletedIsASuccessWithAWarning(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException($this->afterWrite());
        $capabilities->structureState->expects(self::once())->method('ownedBoards')->with('alice')->willReturn([
            new Board(['id' => 3, 'title' => 'Outro']),
            new Board(['id' => 4, 'title' => 'Velho', 'deletedAt' => 1700000500]),
        ]);
        $payload = $this->assertWrittenWithWarning($this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(['id' => 4, 'title' => 'Velho', 'deleted' => true], array_diff_key($payload, ['warnings' => 1]));
    }

    public function testABoardDeletionReadBackAliveIsTheUsualError(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException($this->afterWrite());
        $capabilities->structureState->method('ownedBoards')->willReturn([new Board(['id' => 4, 'title' => 'Velho'])]);
        $this->assertPlainError($this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
    }

    public function testABoardDeletionThatCannotBeCheckedIsUnconfirmed(): void {
        $capabilities = $this->structureGateway();
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException($this->afterWrite());
        $capabilities->structureState->method('ownedBoards')->willThrowException(new \RuntimeException('db gone'));
        $this->assertUnconfirmed(
            $this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_list_boards',
        );
    }
    // --- an approximate read-back never authorizes the next write (third round) ---------------------------------
    /** @param array<string, mixed> $result */
    private function assertProbable(array $result): array {
        self::assertArrayNotHasKey('isError', $result);
        $payload = $this->payload($result);
        self::assertSame('probable', $payload['confirmed']);
        self::assertContains(\OCA\Mcp\Modules\Deck\Execution\WriteMessages::probablyCreated(), $payload['warnings']);
        self::assertNotContains(
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writtenThenFailed(),
            $payload['warnings'],
            'o que é só provável não é dado como gravado',
        );
        return $payload;
    }

    /**
     * Two identical recent cards: the creation fails before the insert and the read-back finds the earlier one by
     * owner, title and time. It is reported as probable and gets no assignment.
     */
    public function testACardFoundByHeuristicIsProbableAndNeverAssigned(): void {
        $capabilities = $this->deckDoublesOwnedBy('alice');
        $capabilities->assignments->method('validateAssignees')->willReturn([['uid' => 'pedro', 'displayName' => 'Pedro']]);
        $capabilities->writer->method('createCard')->willThrowException(new NoPermissionException('Permission denied'));
        $capabilities->state->method('findCreatedCard')->willReturn($this->card(['id' => 41, 'title' => 'Fechar']));
        $capabilities->assignments->expects(self::never())->method('assignCardUser');
        $payload = $this->assertProbable($this->cards($capabilities)['create']->execute(
            ['stackId' => 10, 'title' => 'Fechar', 'assignees' => ['pedro']],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(41, $payload['id']);
        self::assertContains(\OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableCardNotAssigned(), $payload['warnings']);
    }

    public function testAListFoundByHeuristicIsProbable(): void {
        $capabilities = $this->structureGateway();
        $capabilities->stackCreator->method('createStack')->willThrowException($this->afterWrite());
        $capabilities->structureState->method('stacksOf')->willReturnOnConsecutiveCalls([], [new Stack(['id' => 12, 'boardId' => 4, 'title' => 'Revisão', 'order' => 1])]);
        $payload = $this->assertProbable($this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(
            ['boardId' => 4, 'title' => 'Revisão', 'confirm' => true],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(12, $payload['id']);
    }

    /** A board found by heuristic gets no list and no card: every dependent step is reported as not created, with the reason. */
    public function testABoardFoundByHeuristicBuildsNothingInsideIt(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->structureState->method('ownedBoards')->willReturnOnConsecutiveCalls([], [new Board(['id' => 3, 'title' => 'Projeto'])]);
        $capabilities->boardCreator->method('createBoard')->willThrowException($this->afterWrite());
        $capabilities->stackCreator->expects(self::never())->method('createStack');
        $capabilities->writer->expects(self::never())->method('createCard');
        $payload = $this->payload($this->deckTool(CreateBoard::class, $capabilities, $this->logger)->execute(
            [
                'title' => 'Projeto',
                'stacks' => [['title' => 'A fazer', 'cards' => [['title' => 'Um'], ['title' => 'Dois']]], ['title' => 'Feito']],
                'confirm' => true,
            ],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(['id' => 3, 'title' => 'Projeto', 'confirmed' => 'probable'], $payload['created']['board']);
        self::assertSame([], $payload['created']['stacks']);
        self::assertSame(
            [
                [
                    'kind' => 'list',
                    'title' => 'A fazer',
                    'reason' => \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableParentNotBuilt(),
                    'cardsNotCreated' => 2,
                ],
                [
                    'kind' => 'list',
                    'title' => 'Feito',
                    'reason' => \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableParentNotBuilt(),
                    'cardsNotCreated' => 0,
                ],
            ],
            $payload['failed'],
        );
        self::assertFalse($payload['complete']);
        self::assertContains(\OCA\Mcp\Modules\Deck\Execution\WriteMessages::probablyCreated(), $payload['warnings']);
    }

    /** A list found by heuristic gets no card; a card found by heuristic gets no assignment. */
    public function testAListAndACardFoundByHeuristicAreNotBuiltOn(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->boardCreator->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
        $capabilities->stackCreator->method('createStack')->willReturnCallback(function (
            string $user,
            int $board,
            string $title,
        ): Stack {
            if ($title === 'Exata') {
                return new Stack(['id' => 21, 'title' => 'Exata']);
            }
            throw $this->afterWrite();
        });
        $capabilities->structureState->method('stacksOf')->willReturn([new Stack(['id' => 20, 'title' => 'Provável'])]);
        $capabilities->writer->method('createCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('findCreatedCard')->willReturn($this->card(['id' => 50, 'title' => 'Um']));
        $capabilities->assignments->expects(self::never())->method('assignCardUser');
        $payload = $this->payload($this->deckTool(CreateBoard::class, $capabilities, $this->logger)->execute(
            [
                'title' => 'Projeto',
                'stacks' => [
                    ['title' => 'Provável', 'cards' => [['title' => 'Um']]],
                    ['title' => 'Exata', 'cards' => [['title' => 'Um', 'assignees' => ['alice']]]],
                ],
                'confirm' => true,
            ],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(
            [
                ['id' => 20, 'title' => 'Provável', 'confirmed' => 'probable', 'cards' => []],
                [
                    'id' => 21,
                    'title' => 'Exata',
                    'cards' => [['id' => 50, 'title' => 'Um', 'confirmed' => 'probable']],
                ],
            ],
            $payload['created']['stacks'],
        );
        self::assertSame(
            [[
                'kind' => 'card',
                'list' => 'Provável',
                'title' => 'Um',
                'reason' => \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableParentNotBuilt(),
            ]],
            $payload['failed'],
        );
        self::assertFalse($payload['complete']);
        self::assertContains(\OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableCardNotAssigned(), $payload['warnings']);
    }
    // --- deck_create_board --------------------------------------------------------------------------------------
    /** The scenario of the review: the board is inserted, a default label fails; the board is found by title, so it is probable and nothing is built in it. */
    public function testABoardSavedBeforeItsLabelsFailedIsProbableAndNotBuiltOn(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->structureState->expects(self::exactly(2))->method('ownedBoards')->with('alice')->willReturnOnConsecutiveCalls(
            [new Board(['id' => 1, 'title' => 'Projeto'])],
            [new Board(['id' => 1, 'title' => 'Projeto']), new Board(['id' => 3, 'title' => 'Projeto'])],
        );
        $capabilities->boardCreator->method('createBoard')->willThrowException($this->afterWrite());
        $capabilities->stackCreator->expects(self::never())->method('createStack');
        $payload = $this->payload($this->deckTool(CreateBoard::class, $capabilities, $this->logger)->execute(
            ['title' => 'Projeto', 'stacks' => [['title' => 'A fazer']], 'confirm' => true],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(['id' => 3, 'title' => 'Projeto', 'confirmed' => 'probable'], $payload['created']['board']);
        self::assertSame([], $payload['created']['stacks']);
        self::assertSame('list', $payload['failed'][0]['kind']);
    }

    public function testABoardThatCannotBeCheckedIsUnconfirmed(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->structureState->method('ownedBoards')->willReturnOnConsecutiveCalls([], self::throwException(new \RuntimeException('db gone')));
        $capabilities->boardCreator->method('createBoard')->willThrowException($this->afterWrite());
        $capabilities->stackCreator->expects(self::never())->method('createStack');
        $this->assertUnconfirmed(
            $this->deckTool(CreateBoard::class, $capabilities, $this->logger)->execute(['title' => 'Projeto', 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            'deck_list_boards',
        );
    }

    public function testABoardThatIsNotThereIsTheUsualError(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->structureState->method('ownedBoards')->willReturn([new Board(['id' => 1, 'title' => 'Projeto'])]);
        $capabilities->boardCreator->method('createBoard')->willThrowException($this->afterWrite());
        $this->assertPlainError($this->deckTool(CreateBoard::class, $capabilities, $this->logger)->execute(['title' => 'Projeto', 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
    }

    /** Inside the build, a card found after Deck failed is listed as probable; one that cannot be checked is a warning. */
    public function testCardsOfTheBuildAreCheckedToo(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->boardCreator->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
        $capabilities->stackCreator->method('createStack')->willReturnCallback(function (
            string $user,
            int $board,
            string $title,
        ): Stack {
            return new Stack(['id' => $title === 'Feito' ? 21 : 20, 'title' => $title]);
        });
        $capabilities->writer->method('createCard')->willThrowException($this->afterWrite());
        $capabilities->state->method('findCreatedCard')->willReturnCallback(function (
            string $user,
            int $stack,
            string $title,
            array $exclude,
        ): ?\OCA\Deck\Db\Card {
            return match ($title) {
                'Um' => $this->card(['id' => 50, 'title' => 'Um']),
                'Dois' => $exclude === [50] ? throw new \RuntimeException('db gone') : null,
                default => null,
            };
        });
        $payload = $this->payload($this->deckTool(CreateBoard::class, $capabilities, $this->logger)->execute(
            [
                'title' => 'Projeto',
                'stacks' => [['title' => 'A fazer', 'cards' => [['title' => 'Um'], ['title' => 'Dois']]], ['title' => 'Feito']],
                'confirm' => true,
            ],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame([20, 21], array_column($payload['created']['stacks'], 'id'));
        self::assertSame([50], array_column($payload['created']['stacks'][0]['cards'], 'id'));
        self::assertSame(
            [['id' => 50, 'title' => 'Um', 'confirmed' => 'probable']],
            $payload['created']['stacks'][0]['cards'],
        );
        self::assertSame([], $payload['failed'], 'nada que foi ou pode ter sido gravado vai para failed');
        self::assertFalse($payload['complete']);
        self::assertContains(\OCA\Mcp\Modules\Deck\Execution\WriteMessages::probablyCreated(), $payload['warnings']);
        self::assertContains(
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::boardItemUnconfirmed('card', 'Dois'),
            $payload['warnings'],
        );
    }
}
