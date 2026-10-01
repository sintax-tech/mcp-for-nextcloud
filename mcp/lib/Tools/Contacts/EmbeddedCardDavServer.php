<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OC;
use OCA\DAV\AppInfo\PluginManager;
use OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin;
use OCA\DAV\CardDAV\HasPhotoPlugin;
use OCA\DAV\CardDAV\ImageExportPlugin;
use OCA\DAV\CardDAV\MultiGetExportPlugin;
use OCA\DAV\CardDAV\PhotoCache;
use OCA\DAV\CardDAV\Plugin;
use OCA\DAV\CardDAV\Security\CardDavRateLimitingPlugin;
use OCA\DAV\CardDAV\Validation\CardDavValidatePlugin;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\DavAclPlugin;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\LockPlugin;
use OCA\DAV\Connector\Sabre\MaintenancePlugin;
use OCA\DAV\Connector\Sabre\Server as EmbeddedServer;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\DAV\RootCollection;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Log\LoggerInterface;
use Sabre\CardDAV\VCFExportPlugin;
use Sabre\DAV\Server as DavServer;
use Sabre\DAV\Sync\Plugin as SyncPlugin;

/**
 * Mirrors stable33 EmbeddedCalDavServer's embedded lifecycle for the CardDAV subtree.
 * CardDAV plugins match apps/dav/lib/Server.php's addressbook block (213-222): core validation,
 * rate limits, photo/export helpers, ACL and app plugins. No direct backend writes or HTTP auth.
 * The authenticated MCP session is checked and pinned by EmbeddedCardDavDispatcher.
 */
final class EmbeddedCardDavServer {
    /**
     * Builds a fresh embedded CardDAV server with the native core plugins and ACL.
     *
     * @return DavServer
     * @throws ContainerExceptionInterface when a native DAV dependency cannot be resolved
     */
    public static function create(): DavServer {
        $root = new RootCollection();
        $server = new EmbeddedServer(new CachingTree($root));
        $base = OC::$WEBROOT . '/remote.php/dav/';
        $server->setBaseUri($base);
        $server->httpRequest->setUrl($base);
        $logger = Server::get(LoggerInterface::class);
        $server->setLogger($logger);
        $server->addPlugin(
            new MaintenancePlugin(Server::get(IConfig::class), Server::get(IFactory::class)->get('dav'))
        );
        $server->addPlugin(new CustomPrincipalPlugin());
        Server::get(IEventDispatcher::class)->dispatchTyped(new SabrePluginAuthInitEvent($server));
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
        $server->addPlugin(new ImageExportPlugin(Server::get(PhotoCache::class)));
        $server->addPlugin(Server::get(CardDavRateLimitingPlugin::class));
        $server->addPlugin(Server::get(CardDavValidatePlugin::class));
        $server->on(
            'beforeMethod:*',
            static function () use ($server, $root): void {
                $manager = new PluginManager(OC::$server, Server::get(IAppManager::class));
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
