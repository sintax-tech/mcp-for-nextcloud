<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Integration;

use OCA\DAV\AppInfo\PluginManager;
use OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin;
use OCA\DAV\Comments\CommentsPlugin;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\DavAclPlugin;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\MaintenancePlugin;
use OCA\DAV\Connector\Sabre\Server as EmbeddedServer;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\DAV\RootCollection;
use OCA\DAV\SystemTag\SystemTagPlugin;
use OCA\Mcp\Platform\Dav\MetadataServer;
use OCP\App\IAppManager;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server as DavServer;

/**
 * The comments and system-tag part of `OCA\DAV\Server`, embedded the way Contacts embeds its CardDAV part.
 *
 * Same tree (`RootCollection`, whose `comments` and `systemtags-relations` children check the user from
 * `IUserSession`), same plugins as apps/dav/lib/Server.php of v34.0.4 / v35.0.1: maintenance (135 / 137), the
 * `SabrePluginAuthInitEvent` (168-169 / 170-171), the exception logger (190 / 192), the ACL with its principal set
 * (195-202 / 197-204), `SystemTagPlugin` (241 / 243) and `CommentsPlugin` (244-247 / 246-249). The only difference is
 * the `auth` plugin: `CustomPrincipalPlugin` instead of the backend chain, because the request is already
 * authenticated by the MCP endpoint and the backend chain would log that session out
 * ({@see \OCA\Mcp\Platform\Dav\PinnedDispatch}). Plugins and collections other apps add to the native server are
 * added here too ({@see AppDavPlugins}), so their vetos guard the tag and comment writes. Nothing is sent over HTTP.
 */
final class EmbeddedMetadataDavServer implements MetadataServer {
	/**
	 * {@inheritDoc}
	 *
	 * @throws \Psr\Container\ContainerExceptionInterface when a native DAV dependency cannot be resolved
	 */
	public function create(string $path): DavServer {
		$root = new RootCollection();
		$server = new EmbeddedServer(new CachingTree($root));
		$base = Server::get(IURLGenerator::class)->getWebroot() . '/remote.php/dav/';
		$server->setBaseUri($base);
		// CommentsPlugin::initialize() reads the request URI while it is added (CommentsPlugin.php:68 / 69).
		$server->httpRequest->setUrl($base . $path);
		$logger = Server::get(LoggerInterface::class);
		$server->setLogger($logger);
		$server->addPlugin(
			new MaintenancePlugin(Server::get(IConfig::class), Server::get(IFactory::class)->get('dav'))
		);
		$server->addPlugin(new CustomPrincipalPlugin());
		Server::get(IEventDispatcher::class)->dispatchTyped(new SabrePluginAuthInitEvent($server));
		$server->addPlugin(new ExceptionLoggerPlugin('webdav', $logger));
		$acl = new DavAclPlugin();
		$acl->principalCollectionSet = [
			'principals/users',
			'principals/groups',
			'principals/calendar-resources',
			'principals/calendar-rooms',
		];
		$server->addPlugin($acl);
		$server->addPlugin(Server::get(SystemTagPlugin::class));
		$server->addPlugin(
			new CommentsPlugin(Server::get(ICommentsManager::class), Server::get(IUserSession::class))
		);
		AppDavPlugins::install($server, $root, Server::get(IEventDispatcher::class), static function (): array {
			// The server container itself, which PluginManager requires, without the private \OC::$server (as Contacts).
			$manager = new PluginManager(Server::get(ContainerInterface::class), Server::get(IAppManager::class));
			return [$manager->getAppPlugins(), $manager->getAppCollections()];
		});
		return $server;
	}
}
