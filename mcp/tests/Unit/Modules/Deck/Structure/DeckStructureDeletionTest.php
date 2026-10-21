<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Structure;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCA\Mcp\Modules\Deck\Execution\DeckUnconfirmedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckStructureDeletionTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckStructureAdapterFixtures;
    public function testDeleteEmptyStackCountsAgainRightBeforeAndAfterDeleting(): void {
        $deleted = new Stack(['id' => 10]);
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->willReturnCallback(function (): array {
            $this->calls[] = 'count';
            return [];
        });
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[StackService::class]->expects(self::once())->method('delete')->with(10)->willReturnCallback(function () use ($deleted): Stack {
            $this->calls[] = 'delete';
            return $deleted;
        });
        self::assertSame($deleted, $this->capabilities->deletion->deleteEmptyStack('alice', 10));
        // Counted before the delete and once more after it, to catch a card created in between.
        self::assertSame(
            ['count', 'delete', 'count'],
            array_values(array_filter($this->calls, static fn(string $c): bool => !str_starts_with($c, 'setUserId'))),
        );
    }

    /** The card appeared between the plan and the confirmation: nothing is deleted and the refusal says how many. */
    public function testDeleteEmptyStackRefusesWhenACardAppearedAndNeverDeletes(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->willReturn([$this->card(['id' => 1])]);
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([$this->card(['id' => 2, 'archived' => true])]);
        $this->services[StackService::class]->expects(self::never())->method('delete');
        try {
            $this->capabilities->deletion->deleteEmptyStack('alice', 10);
            self::fail('deleted a list that holds cards');
        } catch (DeckRefusalException $e) {
            self::assertSame('The list has 2 cards; move or delete them first.', $e->getMessage());
        }
    }

    /**
     * A card created between the count and the delete: the list is recounted after the delete, restored through
     * the Deck update (no undo exists for a list) and the call is refused, so the card is not left in the trash.
     */
    public function testDeleteEmptyStackRestoresTheListWhenACardArrivedDuringTheDelete(): void {
        $deleted = false;
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->willReturnCallback(function () use (&$deleted): array {
            return $deleted ? [$this->card(['id' => 7])] : [];
        });
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[StackService::class]->expects(self::once())->method('delete')->with(10)->willReturnCallback(function () use (&$deleted): Stack {
            $deleted = true;
            $this->calls[] = 'delete';
            return new Stack(['id' => 10, 'boardId' => 4, 'title' => 'A fazer', 'order' => 2]);
        });
        $this->services[StackService::class]->expects(self::once())->method('update')->with(10, 'A fazer', 4, 2, 0)->willReturnCallback(function (): Stack {
            $this->calls[] = 'restore';
            return new Stack(['id' => 10]);
        });
        try {
            $this->capabilities->deletion->deleteEmptyStack('alice', 10);
            self::fail('left a list that received a card in the trash');
        } catch (DeckRefusalException $e) {
            self::assertSame('The list received cards while it was being deleted; nothing was deleted.', $e->getMessage());
        }
        self::assertSame(
            ['delete', 'restore'],
            array_values(array_filter($this->calls, static fn(string $c): bool => !str_starts_with($c, 'setUserId'))),
        );
    }

    /** A restore that fails is not hidden behind "nothing was deleted": the person is told where to recover the list. */
    public function testDeleteEmptyStackSaysSoWhenTheRestoreFails(): void {
        $deleted = false;
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->willReturnCallback(function () use (&$deleted): array {
            return $deleted ? [$this->card(['id' => 7])] : [];
        });
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[StackService::class]->method('delete')->willReturnCallback(function () use (&$deleted): Stack {
            $deleted = true;
            return new Stack(['id' => 10, 'boardId' => 4, 'title' => 'A fazer', 'order' => 2]);
        });
        $this->services[StackService::class]->method('update')->willThrowException(new \RuntimeException('secret detail'));
        // Read again, the list is still in the trash.
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        $this->services[StackMapper::class]->method('findDeleted')->willReturn([new Stack(['id' => 10, 'boardId' => 4])]);
        try {
            $this->capabilities->deletion->deleteEmptyStack('alice', 10);
            self::fail('answered success for a list that is in the trash with a card');
        } catch (DeckRefusalException $e) {
            self::assertSame(
                'The list received cards while it was being deleted and could not be restored; recover it from the Deck trash.',
                $e->getMessage(),
            );
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    /** Third round: `StackService::update()` writes `deleted_at = 0` before its events; a listener throwing leaves the list active. */
    public function testDeleteEmptyStackReadsTheListAgainWhenTheRestoreThrowsAfterRestoring(): void {
        $this->stackWhoseRestoreThrows();
        $this->services[StackMapper::class]->method('findDeleted')->willReturn([]);
        $this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10, 'boardId' => 4])]);
        try {
            $this->capabilities->deletion->deleteEmptyStack('alice', 10);
            self::fail('refused nothing although the list is active');
        } catch (DeckRefusalException $e) {
            self::assertSame('The list received cards while it was being deleted; nothing was deleted.', $e->getMessage());
            self::assertFalse($e->afterWrite());
        }
    }

    /** When the list cannot be read again, nobody can say where it is: unconfirmed, never a claim of either state. */
    public function testDeleteEmptyStackIsUnconfirmedWhenTheListCannotBeReadAfterTheRestoreThrew(): void {
        $this->stackWhoseRestoreThrows();
        $this->services[StackMapper::class]->method('findDeleted')->willThrowException(new \RuntimeException('db gone'));
        try {
            $this->capabilities->deletion->deleteEmptyStack('alice', 10);
            self::fail('claimed a state that was not read');
        } catch (DeckUnconfirmedException $e) {
            self::assertSame('deck_list_stacks', $e->readWith);
        }
    }

    public function testDeleteEmptyStackWithoutNewCardsNeverRestores(): void {
        $deleted = new Stack(['id' => 10, 'boardId' => 4]);
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->willReturn([]);
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[StackService::class]->method('delete')->willReturn($deleted);
        $this->services[StackService::class]->expects(self::never())->method('update');
        self::assertSame($deleted, $this->capabilities->deletion->deleteEmptyStack('alice', 10));
    }

    /** A card created between the count and the delete: the board is recounted, brought back with Deck's own undo and refused. */
    public function testDeleteEmptyBoardUndoesTheDeleteWhenACardArrivedDuringIt(): void {
        $deleted = false;
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
        $this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
        $this->services[CardMapper::class]->method('findAll')->willReturnCallback(function () use (&$deleted): array {
            return $deleted ? [$this->card(['id' => 7])] : [];
        });
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[BoardService::class]->expects(self::once())->method('delete')->with(4)->willReturnCallback(function () use (&$deleted): \OCA\Deck\Db\Board {
            $deleted = true;
            $this->calls[] = 'delete';
            return new \OCA\Deck\Db\Board(['id' => 4]);
        });
        $this->services[BoardService::class]->expects(self::once())->method('deleteUndo')->with(4)->willReturnCallback(function (): \OCA\Deck\Db\Board {
            $this->calls[] = 'undo';
            return new \OCA\Deck\Db\Board(['id' => 4]);
        });
        try {
            $this->capabilities->deletion->deleteEmptyBoard('alice', 4);
            self::fail('left a board that received a card in the trash');
        } catch (DeckRefusalException $e) {
            self::assertSame('The board received cards while it was being deleted; nothing was deleted.', $e->getMessage());
        }
        self::assertSame(
            ['delete', 'undo'],
            array_values(array_filter($this->calls, static fn(string $c): bool => !str_starts_with($c, 'setUserId'))),
        );
    }

    public function testDeleteEmptyBoardSaysSoWhenTheUndoFails(): void {
        $deleted = false;
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
        $this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
        $this->services[CardMapper::class]->method('findAll')->willReturnCallback(function () use (&$deleted): array {
            return $deleted ? [$this->card(['id' => 7])] : [];
        });
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[BoardService::class]->method('delete')->willReturnCallback(function () use (&$deleted): \OCA\Deck\Db\Board {
            $deleted = true;
            return new \OCA\Deck\Db\Board(['id' => 4]);
        });
        $this->services[BoardService::class]->method('deleteUndo')->willThrowException(new \RuntimeException('secret detail'));
        // Read again, the board is still in the trash.
        $this->services[BoardMapper::class]->method('findAllByOwner')->willReturn([new Board(['id' => 4, 'owner' => 'alice', 'deletedAt' => 1700000500])]);
        try {
            $this->capabilities->deletion->deleteEmptyBoard('alice', 4);
            self::fail('answered success for a board that is in the trash with a card');
        } catch (DeckRefusalException $e) {
            self::assertSame(
                'The board received cards while it was being deleted and could not be restored; recover it from the Deck trash.',
                $e->getMessage(),
            );
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    /** Third round: `BoardService::deleteUndo()` writes `deleted_at = 0` before its events; a listener throwing leaves the board active. */
    public function testDeleteEmptyBoardReadsTheBoardAgainWhenTheUndoThrowsAfterRestoring(): void {
        $this->boardWhoseUndoThrows();
        $this->services[BoardMapper::class]->method('findAllByOwner')->willReturn([new Board(['id' => 4, 'owner' => 'alice', 'deletedAt' => 0])]);
        try {
            $this->capabilities->deletion->deleteEmptyBoard('alice', 4);
            self::fail('refused nothing although the board is active');
        } catch (DeckRefusalException $e) {
            self::assertSame('The board received cards while it was being deleted; nothing was deleted.', $e->getMessage());
            self::assertFalse($e->afterWrite());
        }
    }

    public function testDeleteEmptyBoardIsUnconfirmedWhenTheBoardCannotBeReadAfterTheUndoThrew(): void {
        $this->boardWhoseUndoThrows();
        $this->services[BoardMapper::class]->method('findAllByOwner')->willThrowException(new \RuntimeException('db gone'));
        try {
            $this->capabilities->deletion->deleteEmptyBoard('alice', 4);
            self::fail('claimed a state that was not read');
        } catch (DeckUnconfirmedException $e) {
            self::assertSame('deck_list_boards', $e->readWith);
        }
    }

    public function testDeleteEmptyBoardIsOnlyForTheOwner(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Comercial', 'owner' => 'pedro']));
        $this->services[StackMapper::class]->expects(self::never())->method('findAll');
        $this->services[BoardService::class]->expects(self::never())->method('delete');
        try {
            $this->capabilities->deletion->deleteEmptyBoard('alice', 4);
            self::fail('deleted a board of somebody else');
        } catch (DeckRefusalException $e) {
            self::assertSame('Only the owner of a board can delete it.', $e->getMessage());
        }
    }

    public function testDeleteEmptyBoardRefusesWhileAnyListHoldsACard(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
        $this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10]), new Stack(['id' => 11])]);
        $this->services[CardMapper::class]->method('findAll')->willReturn([]);
        $this->services[CardMapper::class]->method('findAllArchived')->willReturnCallback(fn($stackId): array => $stackId === 11 ? [$this->card(['id' => 9, 'archived' => true])] : []);
        $this->services[BoardService::class]->expects(self::never())->method('delete');
        try {
            $this->capabilities->deletion->deleteEmptyBoard('alice', 4);
            self::fail('deleted a board that holds a card');
        } catch (DeckRefusalException $e) {
            self::assertSame('The board has 1 card; move or delete it first.', $e->getMessage());
        }
    }

    public function testDeleteEmptyBoardDeletesTheOwnersEmptyBoard(): void {
        $deleted = new \OCA\Deck\Db\Board(['id' => 4]);
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[BoardService::class]->method('find')->willReturn(new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Meu', 'owner' => 'alice']));
        $this->services[StackMapper::class]->method('findAll')->willReturn([new Stack(['id' => 10])]);
        $this->services[CardMapper::class]->method('findAll')->willReturn([]);
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        $this->services[BoardService::class]->expects(self::once())->method('delete')->with(4)->willReturn($deleted);
        $this->services[BoardService::class]->expects(self::never())->method('deleteUndo');
        self::assertSame($deleted, $this->capabilities->deletion->deleteEmptyBoard('alice', 4));
    }
}
