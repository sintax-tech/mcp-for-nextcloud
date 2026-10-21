<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Access;

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

final class DeckStructureOwnershipTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckStructureAdapterFixtures;
    public function testBoardOwnershipChecksManageBeforeReadingTheOwner(): void {
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(null, 4, Acl::PERMISSION_MANAGE)->willReturnCallback(function (): bool {
            $this->calls[] = 'checkPermission';
            return true;
        });
        $this->services[BoardService::class]->expects(self::once())->method('find')->with(4, false, true)->willReturnCallback(function (): \OCA\Deck\Db\Board {
            $this->calls[] = 'find';
            return new \OCA\Deck\Db\Board(['id' => 4, 'title' => 'Comercial', 'owner' => 'pedro']);
        });
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getDisplayName')->willReturn('Paulo Exemplo');
        $this->services[IUserManager::class]->method('get')->with('pedro')->willReturn($user);
        self::assertSame(
            ['owner' => 'pedro', 'ownerDisplayName' => 'Paulo Exemplo', 'name' => 'Comercial'],
            $this->capabilities->ownership->boardOwnership('alice', 4),
        );
        self::assertSame(
            ['checkPermission', 'find'],
            array_values(array_filter($this->calls, static fn(string $c): bool => !str_starts_with($c, 'setUserId'))),
        );
    }

    public function testBoardOwnershipIsRefusedBeforeAnythingIsReadWithoutManageAccess(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
        $this->services[BoardService::class]->expects(self::never())->method('find');
        $this->expectException(NoPermissionException::class);
        $this->capabilities->ownership->boardOwnership('alice', 4);
    }
}
