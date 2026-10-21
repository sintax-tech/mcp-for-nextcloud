<?php
/*
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/DavAclPlugin.php
// SHA-256: 22c857b3bb3e3263ab5b905b0564e405f4111c7e7c1e7d5931ecef2938c02810

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\Connector\Sabre;

use OCA\DAV\CalDAV\CachedSubscription;
use OCA\DAV\CalDAV\Calendar;
use OCA\DAV\CardDAV\AddressBook;
use Sabre\CalDAV\Principal\User;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
/**
 * Class DavAclPlugin is a wrapper around \Sabre\DAVACL\Plugin that returns 404
 * responses in case the resource to a response has been forbidden instead of
 * a 403. This is used to prevent enumeration of valid resources.
 *
 * @see https://github.com/owncloud/core/issues/22578
 * @package OCA\DAV\Connector\Sabre
 */
class DavAclPlugin extends \Sabre\DAVACL\Plugin
{
    public function __construct()
    {
    }
    public function checkPrivileges($uri, $privileges, $recursion = self::R_PARENT, $throwExceptions = true)
    {
    }
    public function propFind(PropFind $propFind, INode $node)
    {
    }
    public function beforeMethod(RequestInterface $request, ResponseInterface $response)
    {
    }
}
