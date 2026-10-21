<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Access;

use LogicException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Modules\Deck\Followup\CardCriteria;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckSessionException;
use OCA\Deck\Service\AssignmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckServiceOwnershipTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\NativeDeckAdapterFixtures;
    public function testStackOwnershipChecksEditAccessBeforeReadingTheBoard(): void {
        $this->recordSetUserId();
        $this->recordUser('pedro', 'Paulo Exemplo');
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(self::isInstanceOf(StackMapper::class), 10, Acl::PERMISSION_EDIT)->willReturnCallback(function (): bool {
            $this->calls[] = 'checkPermission';
            return true;
        });
        $this->services[StackMapper::class]->expects(self::once())->method('findBoardId')->with(10)->willReturnCallback(function (): int {
            $this->calls[] = 'findBoardId';
            return 4;
        });
        $this->services[BoardService::class]->expects(self::once())->method('find')->with(4, false, true)->willReturn($this->boardDouble(4, 'Comercial', 'pedro'));
        self::assertSame(
            ['owner' => 'pedro', 'ownerDisplayName' => 'Paulo Exemplo', 'name' => 'Comercial'],
            $this->capabilities->ownership->stackOwnership('alice', 10),
        );
        self::assertSame(['checkPermission', 'findBoardId'], $this->record('checkPermission', 'findBoardId'));
    }

    public function testCardOwnershipRunsTheSameCheckTheWriteRuns(): void {
        $this->recordSetUserId();
        $this->recordUser('pedro', 'Paulo Exemplo');
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(self::isInstanceOf(CardMapper::class), 7, Acl::PERMISSION_EDIT)->willReturn(true);
        $this->services[CardMapper::class]->expects(self::once())->method('findBoardId')->with(7)->willReturn(4);
        $this->services[BoardService::class]->expects(self::once())->method('find')->with(4, false, true)->willReturn($this->boardDouble(4, 'Comercial', 'pedro'));
        self::assertSame(
            ['owner' => 'pedro', 'ownerDisplayName' => 'Paulo Exemplo', 'name' => 'Comercial'],
            $this->capabilities->ownership->cardOwnership('alice', 7),
        );
    }

    public function testOwnershipIsRefusedBeforeAnyOwnerIsReadWhenAccessIsDenied(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->method('checkPermission')->willThrowException(new NoPermissionException('Permission denied'));
        $this->services[StackMapper::class]->expects(self::never())->method('findBoardId');
        $this->services[BoardService::class]->expects(self::never())->method('find');
        $this->expectException(NoPermissionException::class);
        $this->capabilities->ownership->stackOwnership('alice', 10);
    }

    public function testOwnershipShowsTheUidWhenTheOwnerAccountIsGone(): void {
        $this->recordSetUserId();
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        $this->services[BoardService::class]->method('find')->willReturn($this->boardDouble(4, 'Comercial', 'ex-usuario'));
        $this->services[IUserManager::class]->method('get')->with('ex-usuario')->willReturn(null);
        self::assertSame(
            ['owner' => 'ex-usuario', 'ownerDisplayName' => 'ex-usuario', 'name' => 'Comercial'],
            $this->capabilities->ownership->stackOwnership('alice', 10),
        );
    }
}
