<?php
/*
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/Validation/CardDavValidatePlugin.php
// SHA-256: e3f9c3f0a559b7d7ba8121806ca97ba221ad50f809de0d8f28a19908332e4833

declare (strict_types=1);
/*
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\CardDAV\Validation;

use OCA\DAV\AppInfo\Application;
use OCP\IAppConfig;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
class CardDavValidatePlugin extends ServerPlugin
{
    public function __construct(private IAppConfig $config)
    {
    }
    public function initialize(Server $server): void
    {
    }
    public function beforePut(RequestInterface $request, ResponseInterface $response): bool
    {
    }
}
