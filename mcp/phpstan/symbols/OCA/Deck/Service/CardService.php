<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/CardService.php
// SHA-256: 618de50ab7538553973f8ec314d519c8e3fe06d655bf2cec22b260d7846a1fa0

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Service;

use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\Activity\ChangeSet;
use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Event\CardCreatedEvent;
use OCA\Deck\Event\CardDeletedEvent;
use OCA\Deck\Event\CardUpdatedEvent;
use OCA\Deck\Model\CardDetails;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Notification\NotificationHelper;
use OCA\Deck\StatusException;
use OCA\Deck\Validators\CardServiceValidator;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
class CardService
{
    public function __construct(private CardMapper $cardMapper, private StackMapper $stackMapper, private BoardMapper $boardMapper, private LabelMapper $labelMapper, private LabelService $labelService, private PermissionService $permissionService, private BoardService $boardService, private NotificationHelper $notificationHelper, private AssignmentMapper $assignedUsersMapper, private AttachmentService $attachmentService, private ActivityManager $activityManager, private ICommentsManager $commentsManager, private IUserManager $userManager, private ChangeHelper $changeHelper, private IEventDispatcher $eventDispatcher, private IURLGenerator $urlGenerator, private LoggerInterface $logger, private IRequest $request, private CardServiceValidator $cardServiceValidator, private AssignmentService $assignmentService, private IReferenceManager $referenceManager, private ?string $userId)
    {
    }
    /**
     * @param Card[] $cards
     * @return CardDetails[]
     */
    public function enrichCards(array $cards): array
    {
    }
    /** @return Card[] */
    public function fetchDeleted($boardId): array
    {
    }
    /**
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function find(int $cardId): Card
    {
    }
    /**
     * @return Card[]
     */
    public function findCalendarEntries(int $boardId): array
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadrequestException
     */
    public function create(string $title, int $stackId, string $type, int $order, string $owner, string $description = '', $duedate = null): Card
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function delete(int $id): Card
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function update(int $id, string $title, int $stackId, string $type, string $owner, string $description = '', int $order = 0, ?string $duedate = null, ?int $deletedAt = null, ?bool $archived = null, ?OptionalNullableValue $done = null): Card
    {
    }
    public function cloneCard(int $id, ?int $targetStackId = null): Card
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function rename(int $id, string $title): Card
    {
    }
    /**
     * @return list<Card>
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function reorder(int $id, int $stackId, int $order): array
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function archive(int $id): Card
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function unarchive(int $id): Card
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function done(int $id): Card
    {
    }
    /**
     * @param $id
     * @return \OCA\Deck\Db\Card
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function undone(int $id): Card
    {
    }
    /**
     * @throws StatusException
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function assignLabel(int $cardId, int $labelId): Card
    {
    }
    /**
     * @throws \OCA\Deck\NoPermissionException
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws BadRequestException
     */
    public function removeLabel(int $cardId, int $labelId): Card
    {
    }
    public function getCardUrl(int $cardId): string
    {
    }
    public function getRedirectUrlForCard(int $cardId): string
    {
    }
}
