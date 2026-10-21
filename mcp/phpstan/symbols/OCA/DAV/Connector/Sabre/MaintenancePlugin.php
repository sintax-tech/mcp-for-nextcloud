<?php
/*
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/MaintenancePlugin.php
// SHA-256: f2ffc2fe2672eb22e503b302f456e81978afd991f49ff69e5745bdadbb25576b

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\Connector\Sabre;

use OCA\DAV\Exception\ServerMaintenanceMode;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Util;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\ServerPlugin;
class MaintenancePlugin extends ServerPlugin
{
    /** @var IL10N */
    private $l10n;
    /**
     * Reference to main server object
     *
     * @var Server
     */
    private $server;
    /**
     * @param IConfig $config
     */
    public function __construct(private IConfig $config, IL10N $l10n)
    {
    }
    /**
     * This initializes the plugin.
     *
     * This function is called by \Sabre\DAV\Server, after
     * addPlugin is called.
     *
     * This method should set up the required event subscriptions.
     *
     * @param \Sabre\DAV\Server $server
     * @return void
     */
    public function initialize(\Sabre\DAV\Server $server)
    {
    }
    /**
     * This method is called before any HTTP method and returns http status code 503
     * in case the system is in maintenance mode.
     *
     * @throws ServiceUnavailable
     * @return bool
     */
    public function checkMaintenanceMode()
    {
    }
}
