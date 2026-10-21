<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Assignments;

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

final class DeckServiceAssignmentsTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\NativeDeckAdapterFixtures;
    /** Board ACLs are evaluated for each assignee by Deck, including group-derived access. */
    public function testAssigneesNeedExistingAccountsAndBoardReadAccess(): void {
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(self::isInstanceOf(StackMapper::class), 10, Acl::PERMISSION_EDIT)->willReturn(true);
        $this->services[StackMapper::class]->method('findBoardId')->with(10)->willReturn(4);
        $this->recordUser('pedro', 'Pedro');
        $this->services[PermissionService::class]->expects(self::once())->method('getPermissions')->with(4, 'pedro')->willReturn([Acl::PERMISSION_READ => true]);
        self::assertSame(
            [['uid' => 'pedro', 'displayName' => 'Pedro']],
            $this->capabilities->assignments->validateAssignees('alice', 10, ['pedro', 'pedro']),
        );
    }

    /** A missing account or a user without access fails before creating anything. */
    public function testAssigneeWithoutBoardAccessIsRejected(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        $this->recordUser('pedro', 'Pedro');
        $this->services[PermissionService::class]->method('getPermissions')->willReturn([Acl::PERMISSION_READ => false]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid argument: assignees');
        $this->capabilities->assignments->validateAssignees('alice', 10, ['pedro']);
    }

    /** Whoever cannot edit the list learns nothing about which accounts exist or reach the board. */
    public function testValidatingAssigneesNeedsEditPermissionOnTheListBeforeAnyProbe(): void {
        $this->services[PermissionService::class]->expects(self::once())->method('checkPermission')->with(self::isInstanceOf(StackMapper::class), 10, Acl::PERMISSION_EDIT)->willThrowException(new NoPermissionException('Permission denied'));
        $this->services[PermissionService::class]->expects(self::never())->method('getPermissions');
        $this->services[IUserManager::class]->expects(self::never())->method('get');
        $this->services[StackMapper::class]->expects(self::never())->method('findBoardId');
        $this->expectException(NoPermissionException::class);
        $this->capabilities->assignments->validateAssignees('alice', 10, ['pedro']);
    }

    /** Missing accounts never reach permission checks or writes. */
    public function testMissingAssigneeIsRejected(): void {
        $this->services[PermissionService::class]->method('checkPermission')->willReturn(true);
        $this->services[StackMapper::class]->method('findBoardId')->willReturn(4);
        $this->services[IUserManager::class]->method('get')->willReturn(null);
        $this->services[PermissionService::class]->expects(self::never())->method('getPermissions');
        try {
            $this->capabilities->assignments->validateAssignees('alice', 10, ['secret-account']);
            self::fail('accepted a missing account');
        } catch (\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException $e) {
            self::assertSame('assignees', $e->details()['field']);
            self::assertStringNotContainsString('secret-account', $e->clientMessage());
        }
    }

    /** The adapter uses the actual assignment service API, with user type as the default. */
    public function testAssignCardUserUsesAssignmentService(): void {
        $service = $this->createMock(\OCA\Deck\Service\AssignmentService::class);
        $this->services[\OCA\Deck\Service\AssignmentService::class] = $service;
        $assignment = new Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => Acl::PERMISSION_TYPE_USER]);
        $service->expects(self::once())->method('assignUser')->with(9, 'pedro')->willReturn($assignment);
        self::assertSame($assignment, $this->capabilities->assignments->assignCardUser('alice', 9, 'pedro'));
    }

    /** Unassigning goes through Deck's assignment service with the user type as default (v1.17.5 signature). */
    public function testUnassignCardUserUsesAssignmentService(): void {
        $service = $this->createMock(\OCA\Deck\Service\AssignmentService::class);
        $this->services[\OCA\Deck\Service\AssignmentService::class] = $service;
        $assignment = new Assignment(['cardId' => 9, 'participant' => 'pedro', 'type' => Acl::PERMISSION_TYPE_USER]);
        $service->expects(self::once())->method('unassignUser')->with(9, 'pedro')->willReturn($assignment);
        self::assertSame($assignment, $this->capabilities->assignments->unassignCardUser('alice', 9, 'pedro'));
    }
}
