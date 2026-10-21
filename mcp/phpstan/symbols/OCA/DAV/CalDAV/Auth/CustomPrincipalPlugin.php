<?php
/*
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CalDAV/Auth/CustomPrincipalPlugin.php
// SHA-256: 7d8d9ff4f8964123b4534e990323f16c6f5f620e4276f3b62067187b19fdbe38

declare (strict_types=1);
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\CalDAV\Auth;

use Sabre\DAV\Auth\Plugin;
/**
 * Set a custom principal uri to allow public requests to its calendar
 */
class CustomPrincipalPlugin extends Plugin
{
    public function setCurrentPrincipal(?string $currentPrincipal): void
    {
    }
}
