<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Integration\DavCalendarStore;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceContext;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
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
			\OCP\ISession::class => $this->createMock(\OCP\ISession::class),
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
		$context->method('registerEventListener')->willReturnCallback(function ($event, $listener, $priority = 0) use (&$events): void {
			$events[$event][] = [$listener, $priority];
		});
		$middlewares = [];
		$context->method('registerMiddleware')->willReturnCallback(function ($class, $global = false) use (&$middlewares): void {
			$middlewares[] = [$class, $global];
		});
		(new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
		$this->assertSame([[\OCA\Mcp\OAuth\Revocation\UserRevocationListener::class, 0]], $events[\OCP\User\Events\UserChangedEvent::class]);
		// Account deletion and group removal go through recheck causes, at the default priority (never reordered).
		foreach ([\OCP\User\Events\BeforeUserDeletedEvent::class, \OCP\User\Events\UserDeletedEvent::class,
			\OCP\Group\Events\BeforeUserRemovedEvent::class, \OCP\Group\Events\UserRemovedEvent::class] as $event) {
			$this->assertSame([[\OCA\Mcp\Listener\UserCauseListener::class, 0]], $events[$event], $event);
		}
		// The GID lifecycle observes deletion and creation of groups, at the default priority.
		foreach ([\OCP\Group\Events\BeforeGroupDeletedEvent::class, \OCP\Group\Events\GroupDeletedEvent::class,
			\OCP\Group\Events\BeforeGroupCreatedEvent::class, \OCP\Group\Events\GroupCreatedEvent::class] as $event) {
			$this->assertSame([[\OCA\Mcp\Listener\GroupLifecycleListener::class, 0]], $events[$event], $event);
		}
		// The opportunistic flush of deferred facts runs at the entry of the app's own routes only.
		$this->assertSame([[\OCA\Mcp\Middleware\DeferredFlushMiddleware::class, false]], $middlewares);
	}

	/**
	 * Verify that the factory supplies both timezone and native session to the composed gateway.
	 *
	 * @return void
	 */
	public function testTheDeckGatewayFactoryBuildsTheGatewayWithItsClockAndTimezone(): void {
		$factories = $this->registeredFactories();
		$this->assertArrayHasKey(DeckGatewayInterface::class, $factories);

		$gateway = $factories[DeckGatewayInterface::class]($this->container());

		$this->assertInstanceOf(DeckServiceGateway::class, $gateway);
		// The timezone is what makes due dates follow the user; a factory that dropped it would still build.
		$zones = (new \ReflectionProperty(DeckServiceGateway::class, 'zones'))->getValue($gateway);
		$this->assertNotNull($zones);
		// Without the session the check that refuses a Deck write under another `userId` is skipped.
		$services = (new \ReflectionProperty(DeckServiceGateway::class, 'services'))->getValue($gateway);
		$this->assertNotNull((new \ReflectionProperty(DeckServiceContext::class, 'session'))->getValue($services));
	}

	public function testCalendarDavIsBoundToTheDispatcherAndReaderStaysLazy(): void {
		$factories = $this->registeredFactories();
		$dispatcher = new \OCA\Mcp\Modules\Calendar\Writing\EmbeddedDavDispatcher(
			static fn () => throw new \RuntimeException('must stay lazy'),
			new \OCA\Mcp\Platform\Dav\Session($this->createMock(\OCP\IUserSession::class)),
			$this->createMock(IConfig::class), new \Psr\Log\NullLogger());
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(\OCA\Mcp\Modules\Calendar\Writing\EmbeddedDavDispatcher::class)->willReturn($dispatcher);
		self::assertSame($dispatcher, $factories[\OCA\Mcp\Modules\Calendar\Writing\CalendarDav::class]($container));
		self::assertInstanceOf(\OCA\Mcp\Modules\Calendar\Integration\DavCalendarSelftestReader::class, $factories[\OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestReader::class]($container));
	}

	/**
	 * The dispatcher builds its server through EmbeddedCalDavServerFactory, which knows the Nextcloud 31 class too.
	 * Without the DAV app (as in this suite) the factory answers with the generic calendar failure; a direct
	 * `new EmbeddedCalDavServer()` would die with "class not found" on Nextcloud 31 instead.
	 */
	public function testTheDispatcherBuildsItsServerThroughTheVersionAwareFactory(): void {
		$factories = $this->registeredFactories();
		$services = [
			\OCP\IUserSession::class => $this->createMock(\OCP\IUserSession::class),
			IConfig::class => $this->createMock(IConfig::class),
			\Psr\Log\LoggerInterface::class => new \Psr\Log\NullLogger(),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $services[$id] ?? throw new \RuntimeException('unexpected service ' . $id),
		);

		$dispatcher = $factories[\OCA\Mcp\Modules\Calendar\Writing\EmbeddedDavDispatcher::class]($container);
		$serverFactory = (new \ReflectionProperty(\OCA\Mcp\Modules\Calendar\Writing\EmbeddedDavDispatcher::class, 'serverFactory'))->getValue($dispatcher);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage(\OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages::DAV_FAILURE);
		$serverFactory();
	}

	public function testTheCalendarStoreFactoryBuildsTheDavStore(): void {
		$factories = $this->registeredFactories();

		$this->assertInstanceOf(DavCalendarStore::class, $factories[CalendarStore::class]($this->container()));
	}

	/**
	 * What the container serves for an id the app did not register: a double, the final tool modules through
	 * the ToolModule contract, and any other final class (which PHPUnit cannot double) autowired from its
	 * constructor types, as Nextcloud's own container does.
	 *
	 * @param \Closure(string):object $get resolver for the constructor dependencies
	 */
	private function unregistered(string $id, \Closure $get): object {
		$class = new \ReflectionClass($id);
		if (!$class->isFinal()) {
			return $this->createMock($id);
		}
		if ($class->implementsInterface(\OCA\Mcp\Mcp\Tools\ToolModule::class)) {
			return $this->createMock(\OCA\Mcp\Mcp\Tools\ToolModule::class);
		}
		$arguments = [];
		foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if ($parameter->isDefaultValueAvailable()) {
				$arguments[] = $parameter->getDefaultValue();
			} elseif ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
				$arguments[] = $get($type->getName());
			} else {
				$this->fail('cannot autowire ' . $id . '::$' . $parameter->getName());
			}
		}

		return $class->newInstanceArgs($arguments);
	}

	/**
	 * The sharing services are autowired, not registered: the server builds them from their constructor types,
	 * as unregistered() does here, and hands the lister to FilesModule through its typed parameter. A
	 * constructor that asks for something the container cannot serve fails here and not on the first share.
	 */
	public function testTheSharingServicesAutowireAndReachTheFilesModule(): void {
		$built = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$built, &$container): object {
				return $built[$id] ??= $this->unregistered($id, static fn (string $dependency): object => $container->get($dependency));
			},
		);

		$lister = $container->get(\OCA\Mcp\Modules\Files\Sharing\ShareLister::class);
		$this->assertInstanceOf(\OCA\Mcp\Modules\Files\Sharing\ShareLister::class, $lister);
		foreach ([\OCA\Mcp\Modules\Files\Sharing\ShareAccess::class, \OCA\Mcp\Modules\Files\Sharing\ShareFormatter::class,
			\OCA\Mcp\Modules\Files\Sharing\ShareRecipientResolver::class, \OCA\Mcp\Modules\Files\Sharing\ShareWriter::class, \OCA\Mcp\Modules\Files\Sharing\ShareRemover::class,
			\OCA\Mcp\Modules\Files\Sharing\LinkPassword::class, \OCA\Mcp\Mcp\Confirmation\PlanState::class] as $service) {
			$this->assertInstanceOf($service, $container->get($service));
		}
		// Invariant 1: a hidden node is never listed nor shared, so the guard cannot be left out by the container.
		$guard = (new \ReflectionProperty(\OCA\Mcp\Modules\Files\Sharing\ShareAccess::class, 'visibilityGuard'))
			->getValue($container->get(\OCA\Mcp\Modules\Files\Sharing\ShareAccess::class));
		$this->assertInstanceOf(\OCA\Mcp\Access\Visibility\VisibilityGuard::class, $guard);
		$this->assertFalse((new \ReflectionMethod(\OCA\Mcp\Modules\Files\Sharing\ShareAccess::class, '__construct'))
			->getParameters()[2]->allowsNull(), 'o guard de visibilidade é obrigatório');

		$parameters = array_column(array_map(
			static fn (\ReflectionParameter $p): array => ['name' => $p->getName(), 'type' => (string)$p->getType()],
			(new \ReflectionMethod(\OCA\Mcp\Modules\Files\FilesModule::class, '__construct'))->getParameters(),
		), 'type', 'name');
		$this->assertSame('?' . \OCA\Mcp\Modules\Files\Sharing\ShareLister::class, $parameters['shareLister']);
		$this->assertSame('?' . \OCA\Mcp\Modules\Files\Sharing\ShareWriter::class, $parameters['shareWriter']);
		$this->assertSame('?' . \OCA\Mcp\Modules\Files\Sharing\ShareRemover::class, $parameters['shareRemover']);
	}

	/**
	 * FileCreation (files_upload, files_create and the create link of the checkout route) is autowired like the sharing
	 * services: the server builds it from its constructor types and hands it to FilesModule and CheckoutController
	 * through their typed parameters. The visibility guard and the file name validator of the core are mandatory, so a
	 * hidden folder or a name Nextcloud refuses can never be skipped because the container left one out.
	 */
	public function testFileCreationAutowiresWithItsGuardsAndReachesTheModuleAndTheController(): void {
		$built = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$built, &$container): object {
				return $built[$id] ??= $this->unregistered($id, static fn (string $dependency): object => $container->get($dependency));
			},
		);

		$creation = $container->get(\OCA\Mcp\Modules\Files\FileCreation::class);
		$this->assertInstanceOf(\OCA\Mcp\Modules\Files\FileCreation::class, $creation);
		$this->assertInstanceOf(\OCA\Mcp\Access\Visibility\VisibilityGuard::class,
			(new \ReflectionProperty(\OCA\Mcp\Modules\Files\FileCreation::class, 'visibilityGuard'))->getValue($creation));
		$this->assertInstanceOf(\OCP\Files\IFilenameValidator::class,
			(new \ReflectionProperty(\OCA\Mcp\Modules\Files\FileCreation::class, 'filenames'))->getValue($creation));
		foreach ((new \ReflectionMethod(\OCA\Mcp\Modules\Files\FileCreation::class, '__construct'))->getParameters() as $parameter) {
			$this->assertFalse($parameter->allowsNull(), 'FileCreation::$' . $parameter->getName() . ' é obrigatório');
		}

		foreach ([\OCA\Mcp\Modules\Files\Creation\CreateFileAction::class, \OCA\Mcp\Modules\Files\Creation\UploadFileAction::class, \OCA\Mcp\Controller\CheckoutController::class] as $class) {
			$parameters = array_column(array_map(
				static fn (\ReflectionParameter $p): array => ['name' => $p->getName(), 'type' => (string)$p->getType()],
				(new \ReflectionMethod($class, '__construct'))->getParameters(),
			), 'type', 'name');
			$this->assertSame('?' . \OCA\Mcp\Modules\Files\FileCreation::class, $parameters['creation'], $class);
		}
	}

	/**
	 * Boots every factory register() declares, the way the server does on the first get(): a service the app
	 * registered is built by its own factory, anything else is a double. A constructor that drifts from its
	 * factory, or a factory that builds a collaborator without what it needs, fails here instead of with an
	 * HTTP 500 on every request in production.
	 */
	public function testEveryRegisteredFactoryBuildsItsService(): void {
		$factories = $this->registeredFactories();
		$built = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$built, &$container, $factories): object {
				if (isset($built[$id])) {
					return $built[$id];
				}
				$service = isset($factories[$id])
					? $factories[$id]($container)
					: $this->unregistered($id, static fn (string $dependency): object => $container->get($dependency));

				return $built[$id] = $service;
			},
		);

		$this->assertArrayHasKey(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class, $factories);
		foreach ($factories as $id => $factory) {
			$service = $container->get($id);
			$this->assertIsObject($service, 'factory of ' . $id . ' returned no object');
		}

		// mcp://guide must render the guide the mcp_guide tool returns, so both come from one ToolGuide.
		$tools = $container->get(\OCA\Mcp\Mcp\Tools\ToolRegistry::class);
		$guide = (new \ReflectionProperty(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class, 'guide'))
			->getValue($container->get(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class));
		$this->assertSame($tools->guide(), $guide);

		// B5: nc://files and the structured references answer to the Files scope; a missing wiring would read as
		// "unrestricted" for the resources, which no unit test of the registry itself can see.
		$this->assertInstanceOf(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class, (new \ReflectionProperty(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class, 'filesGuard'))
			->getValue($container->get(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class)));
		$this->assertInstanceOf(\OCA\Mcp\Access\ResourceScope\FilesReferenceScope::class, $container->get(\OCA\Mcp\Access\ResourceScope\FileReferenceScope::class));
	}
}
