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

final class DeckStructureStackCreatorTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckStructureAdapterFixtures;
    public function testCreateStackWithAnExplicitOrderWritesWithoutReadingTheBoard(): void {
        $stack = new Stack(['id' => 10, 'title' => 'A fazer']);
        $this->services[StackMapper::class]->expects(self::never())->method('findAll');
        $this->services[StackService::class]->expects(self::once())->method('create')->with('A fazer', 4, 2)->willReturn($stack);
        self::assertSame($stack, $this->capabilities->stackCreator->createStack('alice', 4, 'A fazer', 2));
    }

    /** Without an order the list goes after the last one, and the board is only read after the manage check. */
    public function testCreateStackWithoutOrderGoesAfterTheLastList(): void {
        $stack = new Stack(['id' => 12]);
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(null, 4, Acl::PERMISSION_MANAGE)->willReturnCallback(function (): bool {
            $this->calls[] = 'checkPermission';
            return true;
        });
        $this->services[StackMapper::class]->expects(self::once())->method('findAll')->with(4)->willReturnCallback(function (): array {
            $this->calls[] = 'findAll';
            return [new Stack(['id' => 1, 'order' => 0]), new Stack(['id' => 2, 'order' => 5])];
        });
        $this->services[StackService::class]->expects(self::once())->method('create')->with('Feito', 4, 6)->willReturn($stack);
        self::assertSame($stack, $this->capabilities->stackCreator->createStack('alice', 4, 'Feito', null));
        self::assertSame(
            ['checkPermission', 'findAll'],
            array_values(array_filter($this->calls, static fn(string $c): bool => !str_starts_with($c, 'setUserId'))),
        );
    }

    public function testCreateStackOnABoardWithoutListsStartsAtZero(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[StackMapper::class]->method('findAll')->willReturn([]);
        $this->services[StackService::class]->expects(self::once())->method('create')->with('Primeira', 4, 0)->willReturn(new Stack());
        $this->capabilities->stackCreator->createStack('alice', 4, 'Primeira', null);
    }
}
