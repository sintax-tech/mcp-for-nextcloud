<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/BoardService.php
// SHA-256: 7992f1c29faaf3cfbb19f661d761e817778fd6f0212f100a82f3300ceaa401e3

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Service;

use OC\User\LazyUser;
use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\Activity\ChangeSet;
use OCA\Deck\AppInfo\Application;
use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AclMapper;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\IPermissionMapper;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\Session;
use OCA\Deck\Db\SessionMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Event\AclCreatedEvent;
use OCA\Deck\Event\AclDeletedEvent;
use OCA\Deck\Event\AclUpdatedEvent;
use OCA\Deck\Event\BoardUpdatedEvent;
use OCA\Deck\Event\CardCreatedEvent;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Notification\NotificationHelper;
use OCA\Deck\Validators\BoardServiceValidator;
use OCA\Files_Sharing\Event\UserShareAccessUpdatedEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception as DbException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
class BoardService
{
    private ?array $boardsCacheFull = null;
    private ?array $boardsCachePartial = null;
    public function __construct(private BoardMapper $boardMapper, private StackMapper $stackMapper, private CardMapper $cardMapper, private IConfig $config, private IL10N $l10n, private LabelMapper $labelMapper, private AclMapper $aclMapper, private PermissionService $permissionService, private AssignmentService $assignmentService, private NotificationHelper $notificationHelper, private AssignmentMapper $assignedUsersMapper, private ActivityManager $activityManager, private IEventDispatcher $eventDispatcher, private ChangeHelper $changeHelper, private IURLGenerator $urlGenerator, private IDBConnection $connection, private BoardServiceValidator $boardServiceValidator, private SessionMapper $sessionMapper, private IUserManager $userManager, private ?string $userId)
    {
    }
    /**
     * Set a different user than the current one, e.g. when no user is available in occ
     */
    public function setUserId(string $userId): void
    {
    }
    /**
     * Get all boards that are shared with a user, their groups or circles
     */
    public function getUserBoards(?int $since = null, bool $includeArchived = true, ?int $before = null, ?string $term = null): array
    {
    }
    /**
     * @return Board[]
     */
    public function findAll(int $since = -1, bool $fullDetails = false, bool $includeArchived = true): array
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function find(int $boardId, bool $fullDetails = true, bool $allowDeleted = false): Board
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function isArchived($mapper, int $id): bool
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function isDeleted($mapper, int $id): bool
    {
    }
    /**
     * @throws BadRequestException
     */
    public function create(string $title, string $userId, string $color): Board
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function delete(int $id): Board
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function deleteUndo(int $id): Board
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function deleteForce(int $id): Board
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function update(int $id, string $title, string $color, bool $archived): Board
    {
    }
    public function enrichWithBoardSettings(Board $board): void
    {
    }
    public function enrichWithActiveSessions(Board $board): void
    {
    }
    /**
     * @param Acl::PERMISSION_TYPE_* $type
     * @throws BadRequestException
     * @throws NoPermissionException
     */
    public function addAcl(int $boardId, int $type, $participant, bool $edit, bool $share, bool $manage): Acl
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function updateAcl(int $id, bool $edit, bool $share, bool $manage): Acl
    {
    }
    /**
     * @throws DbException
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws MultipleObjectsReturnedException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function deleteAcl(int $id): ?Acl
    {
    }
    public function leave(int $boardId): ?Acl
    {
    }
    /**
     * @throws BadRequestException
     * @throws DbException
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws NoPermissionException
     */
    public function clone(int $id, string $userId, bool $withCards = false, bool $withAssignments = false, bool $withLabels = false, bool $withDueDate = false, bool $moveCardsToLeftStack = false, bool $restoreArchivedCards = false): Board
    {
    }
    public function transferBoardOwnership(int $boardId, string $newOwner, bool $changeContent = false): Board
    {
    }
    public function transferOwnership(string $owner, string $newOwner, bool $changeContent = false): \Generator
    {
    }
    /**
     * @throws DoesNotExistException
     * @throws NoPermissionException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function export(int $id): Board
    {
    }
}
