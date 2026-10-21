<?php
/*
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/ImageExportPlugin.php
// SHA-256: c3d4ad6e299aa8e03a9590d71994fbcc47d747132c4d8d4917b1003fb7dfc0d8

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\CardDAV;

use OCP\AppFramework\Http;
use OCP\Files\NotFoundException;
use Sabre\CardDAV\Card;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
class ImageExportPlugin extends ServerPlugin
{
    /** @var Server */
    protected $server;
    /**
     * ImageExportPlugin constructor.
     *
     * @param PhotoCache $cache
     */
    public function __construct(private PhotoCache $cache)
    {
    }
    /**
     * Initializes the plugin and registers event handlers
     *
     * @param Server $server
     * @return void
     */
    public function initialize(Server $server)
    {
    }
    /**
     * Intercepts GET requests on addressbook urls ending with ?photo.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    public function httpGet(RequestInterface $request, ResponseInterface $response)
    {
    }
}
