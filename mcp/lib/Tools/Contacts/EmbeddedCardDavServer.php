<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Contacts;

use OCA\DAV\AppInfo\PluginManager;
use OCA\DAV\CalDAV\Auth\CustomPrincipalPlugin;
use OCA\DAV\Connector\Sabre\{CachingTree,DavAclPlugin,MaintenancePlugin,LockPlugin,ExceptionLoggerPlugin};
use OCA\DAV\CardDAV\{Plugin,HasPhotoPlugin,MultiGetExportPlugin,ImageExportPlugin,PhotoCache};
use OCA\DAV\CardDAV\Security\CardDavRateLimitingPlugin;
use OCA\DAV\CardDAV\Validation\CardDavValidatePlugin;
use OCA\DAV\RootCollection;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCP\{Server,IConfig};
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Mirrors stable33 EmbeddedCalDavServer's embedded lifecycle for the CardDAV subtree.
 * CardDAV plugins match apps/dav/lib/Server.php's addressbook block (213-222): core validation,
 * rate limits, photo/export helpers, ACL and app plugins. No direct backend writes or HTTP auth.
 * The authenticated MCP session is checked and pinned by EmbeddedCardDavDispatcher.
 */
final class EmbeddedCardDavServer {
    public static function create(): \Sabre\DAV\Server {
        $root=new RootCollection();
        $server=new \OCA\DAV\Connector\Sabre\Server(new CachingTree($root));
        $base=\OC::$WEBROOT.'/remote.php/dav/';
        $server->setBaseUri($base); $server->httpRequest->setUrl($base);
        $logger=Server::get(LoggerInterface::class);
        $server->setLogger($logger);
        $server->addPlugin(new MaintenancePlugin(Server::get(IConfig::class),Server::get(IFactory::class)->get('dav')));
        $server->addPlugin(new CustomPrincipalPlugin());
        Server::get(IEventDispatcher::class)->dispatchTyped(new SabrePluginAuthInitEvent($server));
        $acl=new DavAclPlugin(); $acl->principalCollectionSet=['principals/users','principals/groups'];
        $server->addPlugin($acl);
        $server->addPlugin(new LockPlugin());
        $server->addPlugin(new ExceptionLoggerPlugin('webdav',$logger));
        $server->addPlugin(new \Sabre\DAV\Sync\Plugin());
        $server->addPlugin(new Plugin());
        $server->addPlugin(new \Sabre\CardDAV\VCFExportPlugin());
        $server->addPlugin(new MultiGetExportPlugin());
        $server->addPlugin(new HasPhotoPlugin());
        $server->addPlugin(new ImageExportPlugin(Server::get(PhotoCache::class)));
        $server->addPlugin(Server::get(CardDavRateLimitingPlugin::class));
        $server->addPlugin(Server::get(CardDavValidatePlugin::class));
        $server->on('beforeMethod:*',static function() use($server,$root): void {
            $manager=new PluginManager(\OC::$server,Server::get(IAppManager::class));
            foreach($manager->getAppPlugins() as $plugin) { $server->addPlugin($plugin); }
            foreach($manager->getAppCollections() as $collection) { $root->addChild($collection); }
        });
        return $server;
    }
}
