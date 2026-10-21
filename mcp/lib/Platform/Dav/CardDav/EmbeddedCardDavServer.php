<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Platform\Dav\CardDav;

use OCA\Mcp\Platform\Dav\CardDav\EmbeddedCardDavDispatcher;
use OCA\DAV\AppInfo\PluginManager;
use OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin;
use OCA\DAV\CardDAV\HasPhotoPlugin;
use OCA\DAV\CardDAV\ImageExportPlugin;
use OCA\DAV\CardDAV\MultiGetExportPlugin;
use OCA\DAV\CardDAV\Plugin;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\DavAclPlugin;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\LockPlugin;
use OCA\DAV\Connector\Sabre\MaintenancePlugin;
use OCA\DAV\Connector\Sabre\Server as EmbeddedServer;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\DAV\RootCollection;
use Psr\Container\ContainerExceptionInterface;
use Sabre\CardDAV\VCFExportPlugin;
use Sabre\DAV\Server as DavServer;
use Sabre\DAV\Sync\Plugin as SyncPlugin;
/**
 * Mirrors stable33 EmbeddedCalDavServer's embedded lifecycle for the CardDAV subtree.
 * CardDAV plugins match apps/dav/lib/Server.php's addressbook block (213-222): core validation,
 * rate limits, photo/export helpers, ACL and app plugins. No direct backend writes or HTTP auth.
 * The authenticated MCP session is checked and pinned by EmbeddedCardDavDispatcher.
 */
final class EmbeddedCardDavServer
{
    public function __construct(
        private \Closure $urlGenerator,
        private \Closure $logger,
        private \Closure $config,
        private \Closure $translations,
        private \Closure $events,
        private \Closure $photoCache,
        private \Closure $rateLimiter,
        private \Closure $validator,
        private \Closure $pluginManager,
    )
    {
    }
    /**
     * Builds a fresh embedded CardDAV server with the native core plugins and ACL.
     *
     * @return DavServer
     * @throws ContainerExceptionInterface when a native DAV dependency cannot be resolved
     */
    public function create(): DavServer
    {
        $root = new RootCollection();
        $server = new EmbeddedServer(new CachingTree($root));
        $base = ($this->urlGenerator)()->getWebroot() . '/remote.php/dav/';
        $server->setBaseUri($base);
        $server->httpRequest->setUrl($base);
        $logger = ($this->logger)();
        $server->setLogger($logger);
        $server->addPlugin(new MaintenancePlugin(($this->config)(), ($this->translations)()->get('dav')));
        $server->addPlugin(new CustomPrincipalPlugin());
        ($this->events)()->dispatchTyped(new SabrePluginAuthInitEvent($server));
        $acl = new DavAclPlugin();
        $acl->principalCollectionSet = ['principals/users', 'principals/groups'];
        $server->addPlugin($acl);
        $server->addPlugin(new LockPlugin());
        $server->addPlugin(new ExceptionLoggerPlugin('webdav', $logger));
        $server->addPlugin(new SyncPlugin());
        $server->addPlugin(new Plugin());
        $server->addPlugin(new VCFExportPlugin());
        $server->addPlugin(new MultiGetExportPlugin());
        $server->addPlugin(new HasPhotoPlugin());
        $server->addPlugin(new ImageExportPlugin(($this->photoCache)()));
        $server->addPlugin(($this->rateLimiter)());
        $server->addPlugin(($this->validator)());
        $server->on(
            'beforeMethod:*',
            function () use ($server, $root): void {
                // The server container itself, which PluginManager requires, without the private \OC::$server.
                $manager = ($this->pluginManager)();
                foreach ($manager->getAppPlugins() as $plugin) {
                    $server->addPlugin($plugin);
                }
                foreach ($manager->getAppCollections() as $collection) {
                    $root->addChild($collection);
                }
            }
        );
        return $server;
    }
}
