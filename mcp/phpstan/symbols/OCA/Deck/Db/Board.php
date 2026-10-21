<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Board.php
// SHA-256: 7dbe4f31193b73f05454f92007f6a5558b6434a0859339d9d886c4f57ed9e68d

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

/**
 * @method int getId()
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method int getShared()
 * @method void setShared(int $shared)
 * @method bool isArchived()
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 * @method int getLastModified()
 * @method void setLastModified(int $lastModified)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method string getColor()
 * @method void setColor(string $color)
 */
class Board extends RelationalEntity
{
    protected $title;
    protected $owner;
    protected $color;
    protected $archived = false;
    /** @var Label[]|null */
    protected $labels = null;
    /** @var Acl[]|null */
    protected $acl = null;
    protected $permissions = [];
    protected $users = [];
    protected $shared;
    protected $stacks = [];
    protected $activeSessions = [];
    protected $deletedAt = 0;
    protected $lastModified = 0;
    protected $settings = [];
    public function __construct()
    {
    }
    public function jsonSerialize(): array
    {
    }
    /**
     * @param Label[] $labels
     */
    public function setLabels($labels)
    {
    }
    /**
     * @param Acl[] $acl
     */
    public function setAcl($acl)
    {
    }
    public function getETag()
    {
    }
    /** @returns Acl[]|null */
    public function getAcl(): ?array
    {
    }
    /** @returns Label[]|null */
    public function getLabels(): ?array
    {
    }
}
