<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Tools\CreateStack;
use OCA\Mcp\Modules\Deck\Tools\DeleteBoard;
use OCA\Mcp\Modules\Deck\Tools\DeleteStack;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/**
 * Covers `deck_create_stack`, `deck_delete_stack` and `deck_delete_board`.
 */

final class StructureToolsTest extends TestCase {
    use DeckToolFixtures;
    private LoggerInterface&MockObject $logger;
    protected function setUp(): void {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @param string $owner Owner the board of the lookups reports.
     * @return DeckDoubles Gateway whose board and list lookups report that owner.
     */
    private function capabilities(string $owner = 'alice'): DeckDoubles {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('boardOwnership')->willReturn($this->ownershipOf($owner, 'Comercial'));
        $capabilities->ownership->method('stackOwnership')->willReturn($this->ownershipOf($owner, 'Comercial'));
        return $capabilities;
    }

    public function testCreateStackWritesAtTheEndByDefault(): void {
        $capabilities = $this->capabilities();
        $capabilities->stackCreator->expects(self::once())->method('createStack')->with('alice', 4, 'Revisão', null)->willReturn(new Stack(['id' => 12, 'boardId' => 4, 'title' => 'Revisão', 'order' => 3]));
        $payload = $this->payload($this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(
            ['boardId' => 4, 'title' => '  Revisão ', 'confirm' => true],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertSame(['id' => 12, 'boardId' => 4, 'title' => 'Revisão', 'order' => 3], $payload);
    }

    public function testCreateStackAtAGivenPosition(): void {
        $capabilities = $this->capabilities();
        $capabilities->stackCreator->expects(self::once())->method('createStack')->with('alice', 4, 'Topo', 0)->willReturn(new Stack(['id' => 13, 'boardId' => 4, 'title' => 'Topo', 'order' => 0]));
        $this->payload($this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(
            ['boardId' => 4, 'title' => 'Topo', 'order' => 0, 'confirm' => true],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
    }

    public function testCreateStackRefusesABlankTitleBeforeAnyLookup(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->expects(self::never())->method('boardOwnership');
        $capabilities->stackCreator->expects(self::never())->method('createStack');
        $this->expectException(ArgumentValidationException::class);
        $this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'title' => '   ', 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
    }

    public function testCreateStackOnABoardOfSomebodyElseNeedsConfirmShared(): void {
        $capabilities = $this->capabilities('pedro');
        $capabilities->stackCreator->expects(self::never())->method('createStack');
        $result = $this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'title' => 'Nova', 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($this->payload($result)['requiresConfirmation']);
    }

    public function testCreateStackOnASharedBoardWithConfirmationWrites(): void {
        $capabilities = $this->capabilities('pedro');
        $capabilities->stackCreator->expects(self::once())->method('createStack')->willReturn(new Stack(['id' => 14, 'boardId' => 4, 'title' => 'Nova', 'order' => 1]));
        $result = $this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(
            ['boardId' => 4, 'title' => 'Nova', 'confirm' => true, 'confirm_shared' => true],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        );
        self::assertSame(14, $this->payload($result)['id']);
    }

    public function testCreateStackOnAnUnreadableBoardIsRefusedBeforeWriting(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->ownership->method('boardOwnership')->willThrowException(new NoPermissionException('Permission denied'));
        $capabilities->stackCreator->expects(self::never())->method('createStack');
        $result = $this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'title' => 'Nova', 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorNotFoundOrForbidden(), $this->text($result));
    }

    public function testDeleteStackDeletesAnEmptyList(): void {
        $capabilities = $this->capabilities();
        $capabilities->deletion->expects(self::once())->method('deleteEmptyStack')->with('alice', 10)->willReturn(new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Feito']));
        $payload = $this->payload($this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(['id' => 10, 'boardId' => 4, 'title' => 'Feito', 'deleted' => true], $payload);
    }

    /** The refusal is a result with its own words and a warning in the log, never an alarm: nothing broke. */
    public function testDeleteStackWithCardsIsRefusedWithTheCountAndLoggedAsAWarning(): void {
        $capabilities = $this->capabilities();
        $capabilities->deletion->method('deleteEmptyStack')->willThrowException(DeckRefusalException::stackNotEmpty(3));
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('warning');
        $result = $this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame('The list has 3 cards; move or delete them first.', $this->text($result));
    }

    public function testDeleteStackOnASharedBoardNeedsConfirmShared(): void {
        $capabilities = $this->capabilities('pedro');
        $capabilities->deletion->expects(self::never())->method('deleteEmptyStack');
        $result = $this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($this->payload($result)['requiresConfirmation']);
    }

    public function testDeleteBoardDeletesAnEmptyBoardOfTheOwner(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->deletion->expects(self::once())->method('deleteEmptyBoard')->with('alice', 4)->willReturn(new Board(['id' => 4, 'title' => 'Vazio']));
        $payload = $this->payload($this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));
        self::assertSame(['id' => 4, 'title' => 'Vazio', 'deleted' => true], $payload);
    }

    public function testDeleteBoardWithCardsIsRefused(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardNotEmpty(1));
        $result = $this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertTrue($result['isError']);
        self::assertSame('The board has 1 card; move or delete it first.', $this->text($result));
    }

    public function testDeleteBoardOfSomebodyElseIsRefused(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException(DeckRefusalException::boardNotOwned());
        $result = $this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
        self::assertSame('Only the owner of a board can delete it.', $this->text($result));
    }

    public function testDeckFailuresStayGenericOnEveryStructureTool(): void {
        $capabilities = $this->capabilities();
        $boom = new \RuntimeException('SQLSTATE secret');
        $capabilities->stackCreator->method('createStack')->willThrowException($boom);
        $capabilities->deletion->method('deleteEmptyStack')->willThrowException($boom);
        $capabilities->deletion->method('deleteEmptyBoard')->willThrowException($boom);
        foreach ([
            $this->deckTool(CreateStack::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'title' => 'X', 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            $this->deckTool(DeleteStack::class, $capabilities, $this->logger)->execute(['stackId' => 10, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
            $this->deckTool(DeleteBoard::class, $capabilities, $this->logger)->execute(['boardId' => 4, 'confirm' => true], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')),
        ] as $result) {
            self::assertTrue($result['isError']);
            self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorGeneric(), $this->text($result));
        }
    }
}
