<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Structure;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckVerificationStructureStateTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckVerificationAdapterFixtures;
    public function testStacksOfAreTheActiveListsAfterTheReadCheck(): void {
        $stack = new Stack(['id' => 12]);
        $this->services[StackMapper::class]->expects(self::once())->method('findAll')->with(4)->willReturn([$stack]);
        self::assertSame([$stack], $this->capabilities->structureState->stacksOf('alice', 4));
        self::assertSame(['check:board:4:' . Acl::PERMISSION_READ], $this->checks());
    }

    public function testDeletedStackIsTheListFoundInTheTrash(): void {
        $deleted = new Stack(['id' => 10, 'boardId' => 4]);
        $this->services[StackMapper::class]->method('findBoardId')->with(10)->willReturn(4);
        $this->services[StackMapper::class]->method('findDeleted')->with(4)->willReturn([new Stack(['id' => 9]), $deleted]);
        self::assertSame($deleted, $this->capabilities->structureState->deletedStack('alice', 10));
        self::assertSame(['check:board:4:' . Acl::PERMISSION_READ], $this->checks());
    }

    public function testAListStillActiveIsNotDeleted(): void {
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        $this->services[StackMapper::class]->method('findDeleted')->willReturn([]);
        $this->services[StackMapper::class]->method('findAll')->with(4)->willReturn([new Stack(['id' => 10])]);
        self::assertNull($this->capabilities->structureState->deletedStack('alice', 10));
    }

    /** Neither in the trash nor among the lists: the state cannot be told, and saying "not deleted" would invite a retry. */
    public function testAListFoundNowhereCannotBeTold(): void {
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        $this->services[StackMapper::class]->method('findDeleted')->willReturn([]);
        $this->services[StackMapper::class]->method('findAll')->willReturn([]);
        $this->expectException(\RuntimeException::class);
        $this->capabilities->structureState->deletedStack('alice', 10);
    }

    public function testOwnedBoardsAreReadAgainDeletedIncluded(): void {
        $boards = [new Board(['id' => 3]), new Board(['id' => 4, 'deletedAt' => 1])];
        $this->services[BoardMapper::class]->expects(self::once())->method('findAllByOwner')->with('alice')->willReturn($boards);
        self::assertSame($boards, $this->capabilities->structureState->ownedBoards('alice'));
    }
}
