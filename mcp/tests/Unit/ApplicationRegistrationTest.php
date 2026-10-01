<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\DavCalendarStore;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckServiceGateway;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The factories Application::register() hands to the container run only in production, on the first get() of
 * the service. A constructor that changes without its factory would fail there with a TypeError that no other
 * test reaches, so each factory is executed here against a container that serves what it asks for.
 */
final class ApplicationRegistrationTest extends TestCase {
    /** @return array<string, callable> factories by service name, as register() declared them */
    private function registeredFactories(): array {
        $factories = [];
        $context = $this->createMock(IRegistrationContext::class);
        $context->method('registerService')->willReturnCallback(
            static function (string $name, callable $factory) use (&$factories): void {
                $factories[$name] = $factory;
            },
        );

        // App::__construct() needs a running server; register() does not, so the constructor is skipped.
        $application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $application->register($context);

        return $factories;
    }

    private function container(): ContainerInterface {
        $services = [
            ITimeFactory::class => $this->createMock(ITimeFactory::class),
            IConfig::class => $this->createMock(IConfig::class),
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id): object => $services[$id] ?? throw new \RuntimeException('unexpected service ' . $id),
        );

        return $container;
    }

    public function testUserRevocationListenersAreRegisteredWithOcpEvents(): void {
        $events = [];
        $context = $this->createMock(IRegistrationContext::class);
        $context->method('registerEventListener')->willReturnCallback(function ($event, $listener) use (&$events): void {
            $events[$event] = $listener;
        });
        (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
        foreach ([\OCP\User\Events\UserChangedEvent::class, \OCP\User\Events\UserDeletedEvent::class] as $event) {
            $this->assertSame(\OCA\Mcp\OAuth\UserRevocationListener::class, $events[$event]);
        }
    }

    public function testTheDeckGatewayFactoryBuildsTheGatewayWithItsClockAndTimezone(): void {
        $factories = $this->registeredFactories();
        $this->assertArrayHasKey(DeckGatewayInterface::class, $factories);

        $gateway = $factories[DeckGatewayInterface::class]($this->container());

        $this->assertInstanceOf(DeckServiceGateway::class, $gateway);
        // The timezone is what makes due dates follow the user; a factory that dropped it would still build.
        $zones = (new \ReflectionProperty(DeckServiceGateway::class, 'zones'))->getValue($gateway);
        $this->assertNotNull($zones);
    }

    public function testCalendarDavIsBoundToTheDispatcherAndReaderStaysLazy(): void {
        $factories = $this->registeredFactories();
        $dispatcher = new \OCA\Mcp\Tools\Calendar\EmbeddedDavDispatcher(
            static fn () => throw new \RuntimeException('must stay lazy'),
            new \OCA\Mcp\Tools\Calendar\Session($this->createMock(\OCP\IUserSession::class)),
            $this->createMock(IConfig::class), new \Psr\Log\NullLogger());
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('get')->with(\OCA\Mcp\Tools\Calendar\EmbeddedDavDispatcher::class)->willReturn($dispatcher);
        self::assertSame($dispatcher, $factories[\OCA\Mcp\Tools\Calendar\CalendarDav::class]($container));
        self::assertInstanceOf(\OCA\Mcp\Service\Calendar\DavCalendarSelftestReader::class, $factories[\OCA\Mcp\Service\Calendar\CalendarSelftestReader::class]($container));
    }

    public function testTheCalendarStoreFactoryBuildsTheDavStore(): void {
        $factories = $this->registeredFactories();

        $this->assertInstanceOf(DavCalendarStore::class, $factories[CalendarStore::class]($this->container()));
    }
}
