<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Stack.php
// SHA-256: d06c75a4eebe0d24e1f5c77a36e289f24f1fb093e56540a4bd7e1a81cd0a1c35

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use Sabre\VObject\Component\VCalendar;
/**
 * @method int getId()
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method int getLastModified()
 * @method void setLastModified(int $lastModified)
 * @method \int getOrder()
 * @method void setOrder(int $order)
 * @method Card[] getCards()
 */
class Stack extends RelationalEntity
{
    protected $title;
    protected $boardId;
    protected $deletedAt = 0;
    protected $lastModified = 0;
    protected $cards = [];
    protected $order;
    public function __construct()
    {
    }
    public function setCards($cards)
    {
    }
    public function jsonSerialize(): array
    {
    }
    public function getCalendarObject(): VCalendar
    {
    }
    public function getCalendarPrefix(): string
    {
    }
    public function getETag()
    {
    }
}
