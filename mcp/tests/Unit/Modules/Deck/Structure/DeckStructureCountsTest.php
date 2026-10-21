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

final class DeckStructureCountsTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckStructureAdapterFixtures;
    /** Active and archived cards both count; the trash does not. */
    public function testAStackCountsActiveAndArchivedCards(): void {
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(self::isInstanceOf(StackMapper::class), 10, Acl::PERMISSION_MANAGE)->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->with(10)->willReturn([$this->card(['id' => 1]), $this->card(['id' => 2])]);
        $this->services[CardMapper::class]->method('findAllArchived')->with(10)->willReturn([$this->card(['id' => 3, 'archived' => true])]);
        self::assertSame(3, $this->capabilities->counts->stackCardCount('alice', 10));
    }

    public function testAStackWithoutCardsCountsZero(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[CardMapper::class]->method('findAll')->willReturn([]);
        $this->services[CardMapper::class]->method('findAllArchived')->willReturn([]);
        self::assertSame(0, $this->capabilities->counts->stackCardCount('alice', 10));
    }

    public function testTheCountIsRefusedBeforeReadingCardsWithoutManageAccess(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
        $this->services[CardMapper::class]->expects(self::never())->method('findAll');
        $this->services[CardMapper::class]->expects(self::never())->method('findAllArchived');
        $this->expectException(NoPermissionException::class);
        $this->capabilities->counts->stackCardCount('alice', 10);
    }

    public function testABoardCountsTheCardsOfEveryList(): void {
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(null, 4, Acl::PERMISSION_MANAGE)->willReturn(true);
        $this->services[StackMapper::class]->method('findAll')->with(4)->willReturn([new Stack(['id' => 10]), new Stack(['id' => 11])]);
        $this->services[CardMapper::class]->method('findAll')->willReturnCallback(fn($stackId): array => $stackId === 10 ? [$this->card(['id' => 1])] : []);
        $this->services[CardMapper::class]->method('findAllArchived')->willReturnCallback(fn($stackId): array => $stackId === 11 ? [$this->card(['id' => 2]), $this->card(['id' => 3])] : []);
        self::assertSame(3, $this->capabilities->counts->boardCardCount('alice', 4));
    }
}
