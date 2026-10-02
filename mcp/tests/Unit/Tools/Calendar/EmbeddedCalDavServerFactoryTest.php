<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\CalendarMessages;
use OCA\Mcp\Tools\Calendar\EmbeddedCalDavServerFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/** Stand-in for OCA\DAV\CalDAV\EmbeddedCalDavServer (Nextcloud 32+): it loads the IMipPlugin itself. */
final class FakeEmbeddedCalDavServer {
    /** @var list<bool> the $public argument of every construction */
    public static array $built = [];
    private Server $server;

    public function __construct(bool $public = true) {
        self::$built[] = $public;
        $this->server = new Server();
    }

    public function getServer(): Server {
        return $this->server;
    }
}

/** Stand-in for OCA\DAV\CalDAV\InvitationResponse\InvitationResponseServer (Nextcloud 31): no IMipPlugin. */
final class FakeInvitationResponseServer {
    /** @var list<bool> the $public argument of every construction */
    public static array $built = [];
    /** Plugin the next server starts with, to model a Nextcloud that already loads one. */
    public static ?ServerPlugin $preloaded = null;
    public Server $server;

    public function __construct(bool $public = true) {
        self::$built[] = $public;
        $this->server = new Server();
        if (self::$preloaded !== null) {
            $this->server->addPlugin(self::$preloaded);
        }
    }

    public function getServer(): Server {
        return $this->server;
    }
}

/** Minimal plugin answering to the IMipPlugin name. */
final class FakeImipPlugin extends ServerPlugin {
    public function initialize(Server $server): void {}

    public function getPluginName(): string {
        return 'imip';
    }
}

/**
 * The embedded CalDAV server of Nextcloud 32+ is used as is; on Nextcloud 31, which only has its predecessor, the
 * server gets the IMipPlugin the newer class loads, under the same switch, so invitations keep going out by e-mail.
 */
final class EmbeddedCalDavServerFactoryTest extends TestCase {
    private const MISSING = 'OCA\\Mcp\\Tests\\Unit\\Tools\\Calendar\\NoSuchServer';

    protected function setUp(): void {
        FakeEmbeddedCalDavServer::$built = [];
        FakeInvitationResponseServer::$built = [];
        FakeInvitationResponseServer::$preloaded = null;
    }

    /**
     * @param string $sendInvitations dav/sendInvitations as the admin left it
     * @param ServerPlugin|null $imip what the container serves for the IMipPlugin, or null when it must not be asked
     */
    private function container(string $sendInvitations, ?ServerPlugin $imip): ContainerInterface {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->with('dav', 'sendInvitations', 'yes')->willReturn($sendInvitations);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static fn (string $id): object => match ($id) {
            IAppConfig::class => $config,
            EmbeddedCalDavServerFactory::IMIP_PLUGIN => $imip ?? throw new \RuntimeException('the IMipPlugin must not be loaded'),
            default => throw new \RuntimeException('unexpected service ' . $id),
        });
        return $container;
    }

    public function testNextcloud32AndLaterUseTheEmbeddedServerAsItIs(): void {
        $factory = new EmbeddedCalDavServerFactory($this->container('yes', null), FakeEmbeddedCalDavServer::class, FakeInvitationResponseServer::class);

        $server = $factory->create();

        $this->assertSame([false], FakeEmbeddedCalDavServer::$built, 'the non-public server, the one that takes a pinned principal');
        $this->assertSame([], FakeInvitationResponseServer::$built);
        $this->assertNull($server->getPlugin('imip'));
    }

    public function testNextcloud31UsesThePredecessorAndAddsTheImipPlugin(): void {
        $imip = new FakeImipPlugin();
        $factory = new EmbeddedCalDavServerFactory($this->container('yes', $imip), self::MISSING, FakeInvitationResponseServer::class);

        $server = $factory->create();

        $this->assertSame([false], FakeInvitationResponseServer::$built);
        $this->assertSame($imip, $server->getPlugin('imip'));
    }

    /** dav/sendInvitations off means no IMipPlugin, as on Nextcloud 32+ and in the main DAV server of 31. */
    public function testNextcloud31LeavesTheImipPluginOutWhenInvitationsAreOff(): void {
        $factory = new EmbeddedCalDavServerFactory($this->container('no', null), self::MISSING, FakeInvitationResponseServer::class);

        $this->assertNull($factory->create()->getPlugin('imip'));
    }

    /** A 31.x that already loads the plugin must not get a second one, which would send every e-mail twice. */
    public function testNextcloud31NeverAddsASecondImipPlugin(): void {
        $existing = new FakeImipPlugin();
        FakeInvitationResponseServer::$preloaded = $existing;
        $factory = new EmbeddedCalDavServerFactory($this->container('yes', null), self::MISSING, FakeInvitationResponseServer::class);

        $this->assertSame($existing, $factory->create()->getPlugin('imip'));
    }

    public function testWithoutEitherServerTheCalendarWriteFailsWithTheGenericMessage(): void {
        $factory = new EmbeddedCalDavServerFactory($this->container('yes', null), self::MISSING, self::MISSING);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(CalendarMessages::DAV_FAILURE);
        $factory->create();
    }

    public function testTheDefaultsNameTheRealNextcloudClasses(): void {
        $this->assertSame('OCA\\DAV\\CalDAV\\EmbeddedCalDavServer', EmbeddedCalDavServerFactory::EMBEDDED);
        $this->assertSame('OCA\\DAV\\CalDAV\\InvitationResponse\\InvitationResponseServer', EmbeddedCalDavServerFactory::LEGACY);
        $this->assertSame('OCA\\DAV\\CalDAV\\Schedule\\IMipPlugin', EmbeddedCalDavServerFactory::IMIP_PLUGIN);
    }
}
