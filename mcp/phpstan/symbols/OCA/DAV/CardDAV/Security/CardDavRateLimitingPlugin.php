<?php
/*
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/CardDAV/Security/CardDavRateLimitingPlugin.php
// SHA-256: ebf9b68e92e7863aa1d6eb0433098da99168b66289abe2941942b7f02ef531a5

declare (strict_types=1);
/*
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\CardDAV\Security;

use OC\Security\RateLimiting\Exception\RateLimitExceededException;
use OC\Security\RateLimiting\Limiter;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\DAV\Connector\Sabre\Exception\TooManyRequests;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\ServerPlugin;
use function count;
use function explode;
class CardDavRateLimitingPlugin extends ServerPlugin
{
    public function __construct(private Limiter $limiter, private IUserManager $userManager, private CardDavBackend $cardDavBackend, private LoggerInterface $logger, private IAppConfig $config, private ?string $userId)
    {
    }
    public function initialize(DAV\Server $server): void
    {
    }
    public function beforeBind(string $path): void
    {
    }
}
