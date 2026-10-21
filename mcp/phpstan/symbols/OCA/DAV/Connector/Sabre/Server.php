<?php
/*
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/server/v33.0.0/apps/dav/lib/Connector/Sabre/Server.php
// SHA-256: 893c17e3715e593081d0a70338d1eb423d3f79c027e683b217920ad8e069bd08

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\DAV\Connector\Sabre;

use OC\DB\Connection;
use Override;
use Sabre\DAV\Exception;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Version;
use TypeError;
/**
 * Class \OCA\DAV\Connector\Sabre\Server
 *
 * This class overrides some methods from @see \Sabre\DAV\Server.
 *
 * @see \Sabre\DAV\Server
 */
class Server extends \Sabre\DAV\Server
{
    /** @var CachingTree $tree */
    /**
     * Tracks queries done by plugins.
     * @var array<string, array<int, array<string, array{nodes:int,
     *     queries:int}>>> The keys represent: event name, depth and plugin name
     */
    private array $pluginQueries = [];
    public bool $debugEnabled = false;
    /**
     * @var array<string, array<int, callable>>
     */
    private array $originalListeners = [];
    /**
     * @var array<string, array<int, callable>>
     */
    private array $wrappedListeners = [];
    /**
     * @see \Sabre\DAV\Server
     */
    public function __construct($treeOrNode = null)
    {
    }
    #[Override]
    public function once(string $eventName, callable $callBack, int $priority = 100): void
    {
    }
    #[Override]
    public function on(string $eventName, callable $callBack, int $priority = 100): void
    {
    }
    public function removeListener(string $eventName, callable $listener): bool
    {
    }
    public function removeAllListeners(?string $eventName = null): void
    {
    }
    /**
     *
     * @return void
     */
    public function start()
    {
    }
    /**
     * Returns queries executed by registered plugins.
     * @return array<string, array<int, array<string, array{nodes:int,
     *     queries:int}>>> The keys represent: event name, depth and plugin name
     */
    public function getPluginQueries(): array
    {
    }
}
