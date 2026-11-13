<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Integration;

use Closure;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\SabrePluginEvent;
use Sabre\DAV\ICollection;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\SimpleCollection;

/**
 * The extension points apps/dav/lib/Server.php gives other apps, installed on the embedded metadata server the same
 * way, so a plugin an app adds there (a veto in beforeBind or beforeUnbind, say) also guards the MCP writes.
 *
 * After the core plugins, the legacy `OCA\DAV\Connector\Sabre::addPlugin` event and the typed SabrePluginAddEvent
 * (Server.php 266-268 of v34.0.4, 268-270 of v35.0.1); then, once the request starts, the plugins and collections of
 * `PluginManager` (288 and 413-422 of v34.0.4, 290 and 415-424 of v35.0.1).
 */
final class AppDavPlugins {
	/** Static installation only. */
	private function __construct() {
	}

	/**
	 * @param Server $server embedded server, its core plugins already added
	 * @param SimpleCollection $root tree root the app collections join
	 * @param IEventDispatcher $events dispatcher the apps listen on
	 * @param Closure(): array{0: iterable<ServerPlugin>, 1: iterable<ICollection>} $apps the PluginManager's app plugins and collections
	 * @return void
	 */
	public static function install(Server $server, SimpleCollection $root, IEventDispatcher $events, Closure $apps): void {
		$events->dispatch('OCA\DAV\Connector\Sabre::addPlugin', new SabrePluginEvent($server));
		$events->dispatchTyped(new SabrePluginAddEvent($server));
		$server->on('beforeMethod:*', static function () use ($server, $root, $apps): void {
			[$plugins, $collections] = $apps();
			foreach ($plugins as $plugin) {
				$server->addPlugin($plugin);
			}
			foreach ($collections as $collection) {
				$root->addChild($collection);
			}
		});
	}
}
