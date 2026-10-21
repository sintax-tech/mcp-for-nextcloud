<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/StatusException.php
// SHA-256: b283c77c5c11c657fa863fcf1fee68599836816b227016213cca955114c15e13

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck;

/**
 * User facing exception that can be thrown with an error being reported to the frontend
 * or consumers of the API
 *
 * This exception is catched in the ExceptionMiddleware
 */
class StatusException extends \Exception
{
    public function __construct($message)
    {
    }
    public function getStatus()
    {
    }
}
