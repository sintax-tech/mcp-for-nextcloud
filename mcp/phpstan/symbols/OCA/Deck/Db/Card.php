<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Card.php
// SHA-256: 1e8033b2c6fecdb785abdd2678b137be24c855896283f1938e7e112eb8003c31

declare (strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use DateTime;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
/**
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getDescription()
 * @method string getDescriptionPrev()
 * @method int getStackId()
 * @method void setStackId(int $stackId)
 * @method int getOrder()
 * @method void setOrder(int $order)
 * @method int getLastModified()
 * @method int getCreatedAt()
 * @method bool getArchived()
 * @method string getType()
 * @method void setType(string $type)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method bool getNotified()
 * @method ?DateTime getDone()
 * @method void setDone(?DateTime $done)
 *
 * @method void setLabels(Label[] $labels)
 * @method null|Label[] getLabels()
 *
 * @method void setAssignedUsers(Assignment[] $users)
 * @method null|User[] getAssignedUsers()
 *
 * @method void setAttachments(Attachment[] $attachments)
 * @method null|Attachment[] getAttachments()
 *
 * @method void setAttachmentCount(int $count)
 * @method null|int getAttachmentCount()
 *
 * @method void setCommentsUnread(int $count)
 * @method null|int getCommentsUnread()
 *
 * @method void setCommentsCount(int $count)
 * @method null|int getCommentsCount()
 *
 * @method void setOwner(string $user)
 * @method null|string getOwner()
 *
 * @method void setRelatedStack(Stack $stack)
 * @method null|Stack getRelatedStack()
 *
 * @method void setRelatedBoard(Board $board)
 * @method null|Board getRelatedBoard()
 */
class Card extends RelationalEntity
{
    public const TITLE_MAX_LENGTH = 255;
    protected string $title = '';
    protected $description;
    protected $descriptionPrev;
    protected $stackId;
    protected $type;
    protected $lastModified;
    protected $lastEditor;
    protected $createdAt;
    protected $labels;
    protected $assignedUsers;
    protected array $attachments = [];
    protected int $attachmentCount = 0;
    protected $owner;
    protected $order;
    protected $archived = false;
    protected $done = null;
    protected $duedate;
    protected $notified = false;
    protected $deletedAt = 0;
    protected $commentsUnread = 0;
    protected $commentsCount = 0;
    protected $relatedStack = null;
    protected $relatedBoard = null;
    private $databaseType = 'sqlite';
    public const DUEDATE_FUTURE = 0;
    public const DUEDATE_NEXT = 1;
    public const DUEDATE_NOW = 2;
    public const DUEDATE_OVERDUE = 3;
    public function __construct()
    {
    }
    public function setDatabaseType($type)
    {
    }
    public function getCalendarObject(): VCalendar
    {
    }
    public function getDaysUntilDue(): ?int
    {
    }
    public function getCalendarPrefix(): string
    {
    }
    public function getETag(): string
    {
    }
}
