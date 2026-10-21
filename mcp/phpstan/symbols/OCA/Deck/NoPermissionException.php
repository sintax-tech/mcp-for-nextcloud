<?php
/*
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/NoPermissionException.php
// SHA-256: ace598fa0b9a0a77aaeb7eb6ebe26fc371c867c6834bab6443c16076d2f70c28

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck;

class NoPermissionException extends StatusException
{
    public function __construct($message, $controller = null, $method = null)
    {
    }
    public function getStatus()
    {
    }
}
