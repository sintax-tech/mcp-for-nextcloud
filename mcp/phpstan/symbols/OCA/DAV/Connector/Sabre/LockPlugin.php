<?php
/*
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/LockPlugin.php
// SHA-256: 3da234bea5a5c06eb26e9a22b768642cb4512f7d5cb7b429768152c6adaf1b6a

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\Connector\Sabre;

use OCA\DAV\Connector\Sabre\Exception\FileLocked;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
class LockPlugin extends ServerPlugin
{
    /**
     * Reference to main server object
     *
     * @var \Sabre\DAV\Server
     */
    private $server;
    /**
     * State of the lock
     *
     * @var bool
     */
    private $isLocked;
    /**
     * {@inheritdoc}
     */
    public function initialize(\Sabre\DAV\Server $server)
    {
    }
    public function getLock(RequestInterface $request)
    {
    }
    public function releaseLock(RequestInterface $request)
    {
    }
}
