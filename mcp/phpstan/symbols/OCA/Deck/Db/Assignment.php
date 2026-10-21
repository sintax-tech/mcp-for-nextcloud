<?php
/*
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/Assignment.php
// SHA-256: d895f7efebc21fbc61ff91c689839ba70604330e49129a9506a1101f469f25bb

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use JsonSerializable;
class Assignment extends RelationalEntity implements JsonSerializable
{
    protected $participant;
    protected $cardId;
    protected $type;
    public const TYPE_USER = Acl::PERMISSION_TYPE_USER;
    public const TYPE_GROUP = Acl::PERMISSION_TYPE_GROUP;
    public const TYPE_CIRCLE = Acl::PERMISSION_TYPE_CIRCLE;
    public function __construct()
    {
    }
    public function getTypeString(): string
    {
    }
}
