<?php
/*
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/AssignmentService.php
// SHA-256: 140552e30b81606dbeb32e875095b5725f034674fe2b00a17bd231d24b645570

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Service;

use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AclMapper;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Event\CardUpdatedEvent;
use OCA\Deck\NoPermissionException;
use OCA\Deck\NotFoundException;
use OCA\Deck\Notification\NotificationHelper;
use OCA\Deck\Validators\AssignmentServiceValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;
class AssignmentService
{
    /**
     * @var PermissionService
     */
    private $permissionService;
    /**
     * @var CardMapper
     */
    private $cardMapper;
    /**
     * @var AssignmentMapper
     */
    private $assignedUsersMapper;
    /**
     * @var AclMapper
     */
    private $aclMapper;
    /**
     * @var NotificationHelper
     */
    private $notificationHelper;
    /**
     * @var ChangeHelper
     */
    private $changeHelper;
    /**
     * @var ActivityManager
     */
    private $activityManager;
    /**
     * @var IEventDispatcher
     */
    private $eventDispatcher;
    /** @var string|null */
    private $currentUser;
    /**
     * @var AssignmentServiceValidator
     */
    private $assignmentServiceValidator;
    public function __construct(PermissionService $permissionService, CardMapper $cardMapper, AssignmentMapper $assignedUsersMapper, AclMapper $aclMapper, NotificationHelper $notificationHelper, ActivityManager $activityManager, ChangeHelper $changeHelper, IEventDispatcher $eventDispatcher, AssignmentServiceValidator $assignmentServiceValidator, $userId)
    {
    }
    /**
     * @throws BadRequestException
     * @throws NoPermissionException
     * @throws MultipleObjectsReturnedException
     * @throws DoesNotExistException
     */
    public function assignUser(int $cardId, string $userId, int $type = Assignment::TYPE_USER): Assignment
    {
    }
    /**
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws NoPermissionException
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function unassignUser(int $cardId, string $userId, int $type = 0): Assignment
    {
    }
}
