<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/StackService.php
// SHA-256: b345f8e32109a78b936c30a7a71691f951f04a4649628ac31715351d4081b061

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Service;

use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\Activity\ChangeSet;
use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Event\BoardUpdatedEvent;
use OCA\Deck\Model\CardDetails;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Deck\Validators\StackServiceValidator;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
class StackService
{
    private StackMapper $stackMapper;
    private CardMapper $cardMapper;
    private BoardMapper $boardMapper;
    private LabelMapper $labelMapper;
    private PermissionService $permissionService;
    private BoardService $boardService;
    private CardService $cardService;
    private AssignmentMapper $assignedUsersMapper;
    private AttachmentService $attachmentService;
    private ActivityManager $activityManager;
    private ChangeHelper $changeHelper;
    private LoggerInterface $logger;
    private IEventDispatcher $eventDispatcher;
    private StackServiceValidator $stackServiceValidator;
    public function __construct(StackMapper $stackMapper, BoardMapper $boardMapper, CardMapper $cardMapper, LabelMapper $labelMapper, PermissionService $permissionService, BoardService $boardService, CardService $cardService, AssignmentMapper $assignedUsersMapper, AttachmentService $attachmentService, ActivityManager $activityManager, ChangeHelper $changeHelper, LoggerInterface $logger, IEventDispatcher $eventDispatcher, StackServiceValidator $stackServiceValidator)
    {
    }
    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function find(int $stackId): Stack
    {
    }
    /**
     * @return Stack[]
     * @throws \OCA\Deck\NoPermissionException
     * @throws BadRequestException
     */
    public function findAll(int $boardId, int $since = -1): array
    {
    }
    /**
     * @return Stack[]
     * @throws \OCP\DB\Exception
     */
    public function findCalendarEntries(int $boardId): array
    {
    }
    /**
     * @return Stack[]
     * @throws \OCP\DB\Exception
     */
    public function fetchDeleted(int $boardId): array
    {
    }
    /**
     * @return Stack[]
     * @throws \OCA\Deck\NoPermissionException
     * @throws BadRequestException
     */
    public function findAllArchived(int $boardId): array
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function create(string $title, int $boardId, int $order): Stack
    {
    }
    /**
     * @return Stack The deleted stack.
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function delete(int $id): Stack
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function update(int $id, string $title, int $boardId, int $order, ?int $deletedAt): Stack
    {
    }
    /**
     * @return array<int, Stack> The stacks in the correct order.
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function reorder(int $id, int $order): array
    {
    }
}
