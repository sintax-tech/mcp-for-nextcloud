<?php
/*
 * SPDX-FileCopyrightText: 2017-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud GmbH.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/AppInfo/PluginManager.php
// SHA-256: 78a56d2b52451eb20d8ffeccebcc567c7920a104806b474e0100a0b5055e1e44

declare (strict_types=1);
/**
 * SPDX-FileCopyrightText: 2017-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud GmbH.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\AppInfo;

use OC\ServerContainer;
use OCA\DAV\CalDAV\AppCalendar\AppCalendarPlugin;
use OCA\DAV\CalDAV\Integration\ICalendarProvider;
use OCA\DAV\CardDAV\Integration\IAddressBookProvider;
use OCP\App\IAppManager;
use Psr\Container\ContainerExceptionInterface;
use Sabre\DAV\Collection;
use Sabre\DAV\ServerPlugin;
use function array_map;
use function class_exists;
use function is_array;
/**
 * Manager for DAV plugins from apps, used to register them
 * to the Sabre server.
 */
class PluginManager
{
    /**
     * App plugins
     *
     * @var ServerPlugin[]
     */
    private $plugins = [];
    /**
     * App collections
     *
     * @var Collection[]
     */
    private $collections = [];
    /**
     * Address book plugins
     *
     * @var IAddressBookProvider[]
     */
    private $addressBookPlugins = [];
    /**
     * Calendar plugins
     *
     * @var ICalendarProvider[]
     */
    private $calendarPlugins = [];
    /** @var bool */
    private $populated = false;
    /**
     * Contstruct a PluginManager
     *
     * @param ServerContainer $container server container for resolving plugin classes
     * @param IAppManager $appManager app manager to loading apps and their info
     */
    public function __construct(private ServerContainer $container, private IAppManager $appManager)
    {
    }
    /**
     * Returns an array of app-registered plugins
     *
     * @return ServerPlugin[]
     */
    public function getAppPlugins()
    {
    }
    /**
     * Returns an array of app-registered collections
     *
     * @return array
     */
    public function getAppCollections()
    {
    }
    /**
     * @return IAddressBookProvider[]
     */
    public function getAddressBookPlugins(): array
    {
    }
    /**
     * Returns an array of app-registered calendar plugins
     *
     * @return ICalendarProvider[]
     */
    public function getCalendarPlugins(): array
    {
    }
}
