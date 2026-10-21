<?php
/*
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Events/SabrePluginAuthInitEvent.php
// SHA-256: 75f2bd380a75b921f67b5227d67a928360a062f15e629f7dc3b22d085f892a0f

declare (strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\Events;

use OCP\EventDispatcher\Event;
use Sabre\DAV\Server;
/**
 * This event is triggered during the setup of the SabreDAV server to allow the
 * registration of additional authentication backends.
 *
 * @since 20.0.0
 */
class SabrePluginAuthInitEvent extends Event
{
    /**
     * @since 20.0.0
     */
    public function __construct(private Server $server)
    {
    }
    /**
     * @since 20.0.0
     */
    public function getServer(): Server
    {
    }
}
