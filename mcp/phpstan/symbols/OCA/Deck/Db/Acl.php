<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Acl.php
// SHA-256: 2993e84a33f460b887e66103ce7cd29c80885f45d9f6b5fd95e6e5f1de506671

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

/**
 * @method int getBoardId()
 * @method bool isPermissionEdit()
 * @method void setPermissionEdit(bool $permissionEdit)
 * @method bool isPermissionShare()
 * @method void setPermissionShare(bool $permissionShare)
 * @method bool isPermissionManage()
 * @method void setPermissionManage(bool $permissionManage)
 * @method int getType()
 * @method void setType(int $type)
 * @method bool isOwner()
 * @method void setOwner(int $owner)
 *
 */
class Acl extends RelationalEntity
{
    public const PERMISSION_READ = 0;
    public const PERMISSION_EDIT = 1;
    public const PERMISSION_SHARE = 2;
    public const PERMISSION_MANAGE = 3;
    public const PERMISSION_TYPE_USER = 0;
    public const PERMISSION_TYPE_GROUP = 1;
    public const PERMISSION_TYPE_CIRCLE = 7;
    protected $participant;
    protected $type;
    protected $boardId;
    protected $permissionEdit = false;
    protected $permissionShare = false;
    protected $permissionManage = false;
    protected $owner = false;
    public function __construct()
    {
    }
    public function getPermission(int $permission): bool
    {
    }
}
