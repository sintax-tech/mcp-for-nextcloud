<?php
/*
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/Plugin.php
// SHA-256: f8e87a436df594a6f6c7f9c16fcca69b6c5d2f215f256c59991c0a0ec7ee9757

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\CardDAV;

use OCA\DAV\CardDAV\Xml\Groups;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
class Plugin extends \Sabre\CardDAV\Plugin
{
    public function initialize(Server $server)
    {
    }
    /**
     * Returns the addressbook home for a given principal
     *
     * @param string $principal
     * @return string|null
     */
    protected function getAddressbookHomeForPrincipal($principal)
    {
    }
    /**
     * Adds all CardDAV-specific properties
     *
     * @param PropFind $propFind
     * @param INode $node
     * @return void
     */
    public function propFind(PropFind $propFind, INode $node)
    {
    }
}
