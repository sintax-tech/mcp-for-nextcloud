<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Platform\Dav\CalDav\DavReadStore as DavCalendarStore;
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
    /** Resolve registered factories with real app owners and explicit host doubles. */
    private function sharedContainer(array &$built): ContainerInterface {
        $factories = $this->registeredFactories();
        $container = $this->createMock(ContainerInterface::class);
        $get = function (string $id) use (&$get, &$built, $factories, &$container): object {
            if (isset($built[$id])) { return $built[$id]; }
            self::assertFalse(preg_match('/^OCA\\\\(?:Deck|Talk|DAV|Notes)\\\\/', $id) === 1, 'Optional app resolved eagerly: '.$id);
            if (isset($factories[$id])) { return $built[$id] = $factories[$id]($container); }
            if (!str_starts_with($id, 'OCA\\Mcp\\') || interface_exists($id)) { return $built[$id] = $this->createMock($id); }
            $class = new \ReflectionClass($id);
            $args = [];
            foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                $args[] = $type instanceof \ReflectionNamedType && !$type->isBuiltin()
                    ? $get($type->getName()) : ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : 'mcp');
            }
            return $built[$id] = $class->newInstanceArgs($args);
        };
        $container->method('get')->willReturnCallback($get);
        return $container;
    }

    public function testSharedPolicyFactoriesPreserveInstanceIdentity(): void {
        $config = new InMemoryConfig();
        $built = [IConfig::class => $config->mock($this),
            \OCP\IAppConfig::class => (new InMemoryAppConfig())->worker($this),
            \OCA\Mcp\OAuth\OAuthStore::class => new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore(),
            \OCA\Mcp\Db\LogsGroupsMapper::class => $this->createMock(\OCA\Mcp\Db\LogsGroupsMapper::class)];
        $tag = $this->createMock(\OCP\SystemTag\ISystemTag::class);
        $tag->method('getId')->willReturn('7');
        $tags = $this->createMock(\OCP\SystemTag\ISystemTagManager::class);
        $tags->method('getTagsByIds')->willReturn([$tag]);
        $built[\OCP\SystemTag\ISystemTagManager::class] = $tags;
        $listeners = [];
        $events = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $events->method('addListener')->willReturnCallback(static function ($event, $listener) use (&$listeners): void { $listeners[$event][] = $listener; });
        $built[\OCP\EventDispatcher\IEventDispatcher::class] = $events;
        $container = $this->sharedContainer($built);
        $guard = $container->get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class);
        foreach ([
            \OCA\Mcp\Modules\Files\Contracts\FileResourceReader::class => 'visibilityGuard',
            \OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader::class => 'visibilityGuard',
            \OCA\Mcp\Modules\Files\Checkout\CheckoutWriter::class => 'visibilityGuard',
            \OCA\Mcp\Modules\Files\Sharing\ShareAccess::class => 'visibilityGuard',
            \OCA\Mcp\Access\Logs\LogFileNames::class => 'visibility',
        ] as $owner => $property) {
            $service = $container->get($owner);
            self::assertSame(str_starts_with($owner, 'OCA\\Mcp\\Modules\\Files\\')
                ? $container->get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class) : $guard,
                (new \ReflectionProperty($service, $property))->getValue($service), $owner);
        }
        $guard->setHiddenTagIds(['7']);
        self::assertSame(['7'], $guard->getHiddenTagIds());
        $guard->setHiddenTagIds([]);
        self::assertSame([], $guard->getHiddenTagIds());
        $node = $this->createMock(\OCP\Files\File::class);
        self::assertTrue($guard->isVisible($node));
        $config->app['mcp'][\OCA\Mcp\Access\Visibility\VisibilityGuard::CONFIG_KEY] = '{bad';
        foreach ($listeners as $eventListeners) { foreach ($eventListeners as $listener) { $listener(); } }
        self::assertFalse($guard->isConfigurationValid());
        self::assertFalse($guard->isVisible($node));
        self::assertCount(7, $listeners);
    }

    public function testSharedProtocolFactoriesPreserveComposition(): void {
        $config = new InMemoryConfig();
        $nativeConfig = $config->mock($this);
        $policy = InMemoryConfig::policy($nativeConfig, new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $policy->setGlobalEnabled(true);
        $policy->setEligible('alice', true);
        $policy->setConnected('alice', true);
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('isEnabled')->willReturn(true);
        $session = $this->createMock(\OCP\IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturn($user);
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(false);
        $admin = false;
        // Capture by reference so discovery/execution observe the same changing identity policy.
        $groups = $this->createMock(\OCP\IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(static function () use (&$admin): bool { return $admin; });
        $groups->method('getBackends')->willReturn([new ArrayGroupBackend(static function () use (&$admin): array { return ['admin' => $admin ? ['alice'] : []]; })]);
        $mapper = $this->createMock(\OCA\Mcp\Db\LogsGroupsMapper::class);
        $mapper->method('state')->willReturn(['version' => 1, 'groups' => []]);
        $events = [];
        $dispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(static function ($event) use (&$events): void { $events[] = $event->getParameters()['outcome']; });
        $logFactory = $this->createMock(\OCP\Log\ILogFactory::class);
        $logFactory->expects(self::never())->method('get');
        $built = [IConfig::class => $nativeConfig, \OCA\Mcp\Access\Grants\GrantPolicy::class => $policy,
            \OCA\Mcp\OAuth\OAuthStore::class => new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore(),
            \OCP\IAppConfig::class => (new InMemoryAppConfig())->worker($this),
            \OCP\IUserSession::class => $session, \OCP\IUserManager::class => $users, \OCP\App\IAppManager::class => $apps,
            \OCP\IGroupManager::class => $groups, \OCA\Mcp\Db\LogsGroupsMapper::class => $mapper,
            \OCP\EventDispatcher\IEventDispatcher::class => $dispatcher, \OCP\Log\ILogFactory::class => $logFactory];
        $remote = $this->createMock(\OCP\Security\Ip\IRemoteAddress::class);
        $remote->method('allowsAdminActions')->willReturn(true);
        $built[\OCP\Security\Ip\IRemoteAddress::class] = $remote;
        $container = $this->sharedContainer($built);
        $protocol = $container->get(\OCA\Mcp\Mcp\Protocol\McpProtocol::class);
        $registry = $container->get(\OCA\Mcp\Mcp\Tools\ToolRegistry::class);
        $availability = $container->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class);
        self::assertSame($policy, (new \ReflectionProperty($availability, 'policy'))->getValue($availability));
        $executor = $container->get(\OCA\Mcp\Mcp\Tools\Execution\ToolExecutor::class);
        self::assertSame($availability, (new \ReflectionProperty($executor, 'availability'))->getValue($executor));
        self::assertSame($availability, (new \ReflectionProperty($registry, 'availability'))->getValue($registry));
        $resources = $container->get(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class);
        self::assertSame($registry->guide(), (new \ReflectionProperty($resources, 'guide'))->getValue($resources));
        $request = $this->createMock(\OCP\IRequest::class);
        $request->method('getHeader')->willReturnCallback(static fn ($name) => ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'][$name] ?? '');
        $factory = $this->createMock(\OCP\L10N\IFactory::class);
        $factory->method('get')->willReturn(new \OCA\Mcp\Tests\Unit\L10n\JsonL10n('en'));
        $controller = $this->getMockBuilder(\OCA\Mcp\Controller\McpController::class)->onlyMethods(['readBody'])->setConstructorArgs([
            'mcp', $request, $session, $this->createMock(\OCP\IURLGenerator::class), $policy, $protocol,
            $container->get(\OCA\Mcp\OAuth\ResourceUrl::class), $container->get(\OCA\Mcp\OAuth\AccessTokenAuthenticator::class), new \OCA\Mcp\L10n\UserL10n($factory), InMemoryConfig::noCauses($this), $container->get(\OCA\Mcp\Access\Guests\GuestRestrictions::class),
        ])->getMock();
        $raw = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
        $controller->method('readBody')->willReturnCallback(static function () use (&$raw): string { return $raw; });
        $response = $controller->post();
        self::assertSame(200, $response->getStatus());
        $names = array_column(json_decode($response->render(), true)['result']['tools'], 'name');
        self::assertContains('files_read', $names);
        self::assertNotContains('notes_list', $names);
        self::assertNotContains('logs_list', $names);
        $raw = '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"logs_list"}}';
        self::assertSame('Unknown tool', json_decode($controller->post()->render(), true)['error']['message']);
        $admin = true;
        $policy->setGrant('alice', 'logs', 'read', true);
        $raw = '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"logs_list","arguments":{"limit":"2"}}}';
        self::assertSame(-32602, json_decode($controller->post()->render(), true)['error']['code']);
        self::assertSame(['denied', 'invalid'], $events);
        self::assertArrayNotHasKey(\OCA\Mcp\Modules\Logs\Reading\LogReader::class, $built);
        $policy->setConnected('alice', false);
        self::assertSame(403, $controller->post()->getStatus());
    }

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
        $context->method('registerEventListener')->willReturnCallback(function ($event, $listener) use (&$events): void {
            $events[$event] = $listener;
        });
        (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
        self::assertSame(\OCA\Mcp\OAuth\UserRevocationListener::class, $events[\OCP\User\Events\UserChangedEvent::class]);
        foreach ([\OCP\User\Events\BeforeUserDeletedEvent::class, \OCP\User\Events\UserDeletedEvent::class,
            \OCP\Group\Events\BeforeUserRemovedEvent::class, \OCP\Group\Events\UserRemovedEvent::class] as $event) {
            self::assertSame(\OCA\Mcp\Listener\UserCauseListener::class, $events[$event]);
        }
        foreach ([\OCP\Group\Events\BeforeGroupCreatedEvent::class, \OCP\Group\Events\GroupCreatedEvent::class,
            \OCP\Group\Events\BeforeGroupDeletedEvent::class, \OCP\Group\Events\GroupDeletedEvent::class] as $event) {
            self::assertSame(\OCA\Mcp\Listener\GroupLifecycleListener::class, $events[$event]);
        }
    }

	public function testDeckFactoriesBuildEveryToolLazilyWithRequiredContext(): void {
        $factories = $this->registeredFactories();
        $built = [];
        $container = $this->createMock(ContainerInterface::class);
        $get = function (string $id) use (&$get, &$built, $factories, &$container): object {
            if (isset($built[$id])) { return $built[$id]; }
            self::assertFalse(str_starts_with($id, 'OCA\\Deck\\'), 'Native Deck was resolved before execution: ' . $id);
            if (isset($factories[$id])) { return $built[$id] = $factories[$id]($container); }
            if (!str_starts_with($id, 'OCA\\Mcp\\') || interface_exists($id)) { return $built[$id] = $this->createMock($id); }
            $r = new \ReflectionClass($id);
            $args = [];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
                $t = $p->getType();
                $args[] = $t instanceof \ReflectionNamedType && !$t->isBuiltin() ? $get($t->getName()) : $p->getDefaultValue();
            }
            return $built[$id] = $r->newInstanceArgs($args);
        };
        $container->method('get')->willReturnCallback($get);
        $module = $get(\OCA\Mcp\Modules\Deck\DeckModule::class);
        self::assertCount(13, $module->definitions());
        foreach (\OCA\Mcp\Modules\Deck\DeckToolCatalog::classes() as $tool) {
            self::assertInstanceOf($tool, $get($tool));
        }
        foreach ([\OCA\Mcp\Modules\Deck\Cards\CardWriter::class, \OCA\Mcp\Modules\Deck\Followup\FollowupQuery::class,
            \OCA\Mcp\Modules\Deck\Cards\EditCardPlan::class, \OCA\Mcp\Modules\Deck\Execution\PreviewBoundary::class,
            \OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class] as $class) {
            self::assertInstanceOf(\OCA\Mcp\Platform\Time\UserTimezone::class, (new \ReflectionProperty($class, 'zones'))->getValue($get($class)));
        }
        $caller = $get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class);
        self::assertSame($get(\OCP\ISession::class), (new \ReflectionProperty($caller, 'session'))->getValue($caller));
    }

	public function testTalkFactoriesBuildEveryToolLazilyWithMandatoryVisibility(): void {
        $factories = $this->registeredFactories();
        $built = [];
        $container = $this->createMock(ContainerInterface::class);
        $get = function (string $id) use (&$get, &$built, $factories, &$container): object {
            if (isset($built[$id])) { return $built[$id]; }
            self::assertFalse(str_starts_with($id, 'OCA\\Talk\\'), 'Native Talk was resolved before execution: ' . $id);
            if (isset($factories[$id])) { return $built[$id] = $factories[$id]($container); }
            if (!str_starts_with($id, 'OCA\\Mcp\\') || interface_exists($id)) { return $built[$id] = $this->createMock($id); }
            $r = new \ReflectionClass($id);
            $args = [];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
                $t = $p->getType();
                $args[] = $t instanceof \ReflectionNamedType && !$t->isBuiltin() ? $get($t->getName()) : $p->getDefaultValue();
            }
            return $built[$id] = $r->newInstanceArgs($args);
        };
        $container->method('get')->willReturnCallback($get);
        $module = $get(\OCA\Mcp\Modules\Talk\TalkModule::class);
        self::assertCount(8, $module->definitions());
        foreach (\OCA\Mcp\Modules\Talk\TalkToolCatalog::classes() as $tool) {
            self::assertInstanceOf($tool, $get($tool));
        }
        self::assertInstanceOf(\OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader::class, $get(\OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader::class));
        foreach ([\OCA\Mcp\Modules\Talk\Attachments\UserFileResolver::class, \OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess::class] as $class) {
            self::assertSame($get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class), (new \ReflectionProperty($class, 'visibilityGuard'))->getValue($get($class)));
        }
        self::assertSame($get(\OCP\IUserManager::class), (new \ReflectionProperty(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class, 'userManager'))->getValue($get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)));
    }

    public function testAllDavToolsAndConsumersAutowireWithoutResolvingNativeDav(): void {
        $factories = $this->registeredFactories();
        $built = [];
        $container = $this->createMock(ContainerInterface::class);
        $get = function (string $id) use (&$get, &$built, $factories, &$container): object {
            if (isset($built[$id])) { return $built[$id]; }
            self::assertFalse(str_starts_with($id, 'OCA\DAV\\'), 'Native DAV resolved before execution: '.$id);
            if (isset($factories[$id])) { return $built[$id] = $factories[$id]($container); }
            if (!str_starts_with($id, 'OCA\Mcp\\') || interface_exists($id)) { return $built[$id] = $this->createMock($id); }
            $r = new \ReflectionClass($id);
            $args = [];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
                $t = $p->getType();
                $args[] = $t instanceof \ReflectionNamedType && !$t->isBuiltin() ? $get($t->getName()) : $p->getDefaultValue();
            }
            return $built[$id] = $r->newInstanceArgs($args);
        };
        $container->method('get')->willReturnCallback($get);
        $count = 0;
        foreach (['Calendar'=>7, 'Contacts'=>6, 'Tasks'=>7] as $feature=>$expected) {
            $module = $get('OCA\Mcp\Modules\\'.$feature.'\\'.$feature.'Module');
            self::assertCount($expected, $module->definitions());
            $catalog = 'OCA\Mcp\Modules\\'.$feature.'\\'.$feature.'ToolCatalog';
            foreach ($catalog::classes() as $tool) { self::assertInstanceOf($tool, $get($tool)); $count++; }
        }
        self::assertSame(20, $count);
        self::assertInstanceOf(\OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader::class, $get(\OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader::class));
        self::assertInstanceOf(\OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestRunner::class, $get(\OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestRunner::class));
        self::assertInstanceOf(\OCA\Mcp\Command\CalendarSelftest::class, $get(\OCA\Mcp\Command\CalendarSelftest::class));
        self::assertSame($get(\OCA\Mcp\Platform\Dav\CalDav\CalendarAdministration::class), $get(\OCA\Mcp\Platform\Dav\CalDav\CalendarAclProbe::class));
    }

    public function testCalendarDavIsBoundToTheDispatcherAndReaderStaysLazy(): void {
        $factories = $this->registeredFactories();
        $dispatcher = new \OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher(
            static fn () => throw new \RuntimeException('must stay lazy'),
            new \OCA\Mcp\Platform\Dav\Transport\Session($this->createMock(\OCP\IUserSession::class)),
            $this->createMock(IConfig::class), new \Psr\Log\NullLogger());
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('get')->with(\OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher::class)->willReturn($dispatcher);
        self::assertSame($dispatcher, $factories[\OCA\Mcp\Platform\Dav\CalDav\CalDavWriter::class]($container));
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

        $dispatcher = $factories[\OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher::class]($container);
        $serverFactory = (new \ReflectionProperty(\OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher::class, 'serverFactory'))->getValue($dispatcher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Calendar\Events\EventMessages::DAV_FAILURE);
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

        foreach ([
            \OCA\Mcp\Modules\Files\Tools\ListShares::class => \OCA\Mcp\Modules\Files\Sharing\ShareLister::class,
            \OCA\Mcp\Modules\Files\Tools\ShareFile::class => \OCA\Mcp\Modules\Files\Sharing\ShareWriter::class,
            \OCA\Mcp\Modules\Files\Tools\UnshareFile::class => \OCA\Mcp\Modules\Files\Sharing\ShareRemover::class,
        ] as $handler => $service) {
            $parameter = (new \ReflectionMethod($handler, '__construct'))->getParameters()[1];
            self::assertSame($service, (string)$parameter->getType());
            self::assertFalse($parameter->allowsNull());
        }
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

        $creation = $container->get(\OCA\Mcp\Modules\Files\Creation\FileCreation::class);
        $this->assertInstanceOf(\OCA\Mcp\Modules\Files\Creation\FileCreation::class, $creation);
        $this->assertInstanceOf(\OCA\Mcp\Access\Visibility\VisibilityGuard::class,
            (new \ReflectionProperty(\OCA\Mcp\Modules\Files\Creation\FileCreation::class, 'visibilityGuard'))->getValue($creation));
        $this->assertInstanceOf(\OCP\Files\IFilenameValidator::class,
            (new \ReflectionProperty(\OCA\Mcp\Modules\Files\Creation\FileCreation::class, 'filenames'))->getValue($creation));
        foreach ((new \ReflectionMethod(\OCA\Mcp\Modules\Files\Creation\FileCreation::class, '__construct'))->getParameters() as $parameter) {
            $this->assertFalse($parameter->allowsNull(), 'FileCreation::$' . $parameter->getName() . ' é obrigatório');
        }

        foreach ([\OCA\Mcp\Modules\Files\Checkout\CheckoutWriter::class] as $class) {
            $parameters = array_column(array_map(
                static fn (\ReflectionParameter $p): array => ['name' => $p->getName(), 'type' => (string)$p->getType()],
                (new \ReflectionMethod($class, '__construct'))->getParameters(),
            ), 'type', 'name');
            $this->assertSame('?' . \OCA\Mcp\Modules\Files\Creation\FileCreation::class, $parameters['creation'], $class);
        }
    }

    /**
     * Boots every factory register() declares, the way the server does on the first get(): a service the app
     * registered is built by its own factory, anything else is a double. A constructor that drifts from its
     * factory, or a factory that builds a collaborator without what it needs, fails here instead of with an
     * HTTP 500 on every request in production.
     */
    public function testFilesRegistrationKeepsDiscoveryLazy(): void {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $module = $this->registeredFactories()[\OCA\Mcp\Modules\Files\FilesModule::class]($container);
        self::assertCount(23, $module->definitions());
    }

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

        $files = $container->get(\OCA\Mcp\Modules\Files\FilesModule::class);
        $toolFactories = (new \ReflectionProperty($files, 'factories'))->getValue($files);
        foreach (\OCA\Mcp\Modules\Files\FilesToolCatalog::classes() as $toolClass) {
            $name = $toolClass::definition()->name;
            self::assertInstanceOf($toolClass, $toolFactories[$name](), $name);
        }

        // mcp://guide must render the guide the mcp_guide tool returns, so both come from one ToolGuide.
        $tools = $container->get(\OCA\Mcp\Mcp\Tools\ToolRegistry::class);
        $guide = (new \ReflectionProperty(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class, 'guide'))
            ->getValue($container->get(\OCA\Mcp\Mcp\Resources\ResourceRegistry::class));
        $this->assertSame($tools->guide(), $guide);
    }
    public function testNotesFactoriesAndResourceContractKeepGuardsAndDoNotTouchStorage(): void {
        $factories=$this->registeredFactories();
        $built=[];
        $container=$this->createMock(ContainerInterface::class);
        $get=function(string $id) use (&$get,&$built,&$container,$factories): object {
            if (isset($built[$id])) { return $built[$id]; }
            self::assertFalse(str_starts_with($id,'OCA\Notes\\') || str_starts_with($id,'OCA\FilesLock\\'),'Optional app resolved: '.$id);
            if (isset($factories[$id])) { return $built[$id]=$factories[$id]($container); }
            if (!str_starts_with($id,'OCA\Mcp\\') || interface_exists($id)) {
                $mock=$this->createMock($id);
                if ($id===\OCP\Files\IRootFolder::class) { $mock->method('getUserFolder')->willThrowException(new \LogicException('Storage touched during construction')); }
                if ($id===\OCP\Files\Lock\ILockManager::class) { $mock->method('getLocks')->willThrowException(new \LogicException('Locks touched during construction')); }
                return $built[$id]=$mock;
            }
            $r=new \ReflectionClass($id);$args=[];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
                $t=$p->getType();$args[]=$t instanceof \ReflectionNamedType && !$t->isBuiltin() ? $get($t->getName()) : $p->getDefaultValue();
            }
            return $built[$id]=$r->newInstanceArgs($args);
        };
        $container->method('get')->willReturnCallback($get);
        $module=$get(\OCA\Mcp\Modules\Notes\NotesModule::class);
        self::assertCount(7,$module->definitions());
        self::assertCount(7,$module->guideNotes());
        self::assertNull($module->renderPlan('notes_edit',[]));
        self::assertCount(1,$built,'Metadata must resolve only the module');
        foreach (\OCA\Mcp\Modules\Notes\NotesToolCatalog::classes() as $tool) { self::assertInstanceOf($tool,$get($tool)); }
        foreach ([\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class,\OCA\Mcp\Modules\Notes\Storage\NoteLookup::class,\OCA\Mcp\Modules\Notes\Storage\NoteMetadata::class,\OCA\Mcp\Modules\Notes\Execution\NoteWriteAccess::class] as $class) {
            self::assertSame($get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class),(new \ReflectionProperty($class,'visibilityGuard'))->getValue($get($class)));
        }
        $reader=$get(\OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader::class);
        self::assertInstanceOf(\OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader::class,$reader);
        self::assertSame($get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class),(new \ReflectionProperty($reader,'visibilityGuard'))->getValue($reader));
        $lookup=(new \ReflectionProperty($reader,'lookup'))->getValue($reader);
        self::assertNull((new \ReflectionProperty($lookup,'visibilityGuard'))->getValue($lookup),'Resource asserts visibility after selecting the original candidate');
    }


    public function testPeopleFactoriesKeepDiscoveryLazyAndExecuteBehindTheGrant(): void {
        $factories = $this->registeredFactories();
        $resolved = [];
        $built = [];
        $search = $this->createMock(\OCP\Collaboration\Collaborators\ISearch::class);
        $search->expects(self::once())->method('search')->with('éé', [0], false, 10, 0)
            ->willReturn([['users' => [['value' => ['shareWith' => 'native-user']]]], false]);
        $container = $this->createMock(ContainerInterface::class);
        $get = function (string $id) use (&$get, &$built, &$resolved, $factories, $container, $search): object {
            if (isset($built[$id])) { return $built[$id]; }
            $resolved[] = $id;
            if (isset($factories[$id])) { return $built[$id] = $factories[$id]($container); }
            return $built[$id] = match ($id) {
                \OCP\Collaboration\Collaborators\ISearch::class => $search,
                \Psr\Log\LoggerInterface::class => $this->createMock(\Psr\Log\LoggerInterface::class),
                \OCA\Mcp\Modules\People\Search\PeopleSearch::class => new \OCA\Mcp\Modules\People\Search\PeopleSearch(
                    $get(\OCP\Collaboration\Collaborators\ISearch::class), $get(\Psr\Log\LoggerInterface::class),
                ),
                default => throw new \LogicException('Unexpected People dependency: '.$id),
            };
        };
        $container->method('get')->willReturnCallback($get);
        $module = $get(\OCA\Mcp\Modules\People\PeopleModule::class);
        self::assertCount(1, $module->definitions());
        self::assertCount(3, $module->guideNotes());
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->expects(self::never())->method('isEnabledForUser');
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, $this->createMock(\OCP\IUserManager::class));
        $registry = new \OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->createMock(\Psr\Log\LoggerInterface::class)));
        self::assertSame(['users_search'], array_column($registry->definitions('alice'), 'name'));
        self::assertStringContainsString('users_search', $registry->guide()->result($registry->definitions('alice'), ['module' => 'people'])['content'][0]['text']);
        $policy->setGrant('alice', 'people', 'read', false);
        self::assertSame([], $registry->definitions('alice'));
        try { $registry->call('users_search', ['query' => 'éé'], 'alice'); self::fail('Grant denial must reject the call'); }
        catch (\OCA\Mcp\Mcp\Tools\Validation\UnknownToolException) { self::addToAssertionCount(1); }
        self::assertSame([\OCA\Mcp\Modules\People\PeopleModule::class], $resolved, 'Discovery and denial must not construct search dependencies');
        $policy->setGrant('alice', 'people', 'read', true);
        $result = $registry->call('users_search', ['query' => ' éé '], 'alice');
        self::assertSame([['type' => 'user', 'uid' => 'native-user', 'displayName' => 'native-user']], json_decode($result['content'][0]['text'], true));
    }

    public function testLogsFactoriesKeepRestrictedHooksIndependentAndWithholdUnauditedData(): void {
        $factories = $this->registeredFactories();
        $config = new InMemoryConfig();
        $nativeConfig = $config->mock($this);
        $policy = InMemoryConfig::policy($nativeConfig, new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        $allowed = true;
        $groups = $this->createMock(\OCP\IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(static function () use (&$allowed): bool { return $allowed; });
        $db = new SqliteDatabase($this, [new \OCA\Mcp\Migration\Version001002Date20261008000000()]);
        $mapper = new \OCA\Mcp\Db\LogsGroupsMapper($db->connection(), (new InMemoryAppConfig())->worker($this));
        $groups->method('getBackends')->willReturn([new \OCA\Mcp\Tests\Unit\ArrayGroupBackend(static function () use (&$allowed): array { return ['admin' => $allowed ? ['root'] : []]; })]);
        $access = \OCA\Mcp\Tests\Unit\InMemoryConfig::logsAccess($this, $nativeConfig, $groups, $mapper, new InMemoryLocks());
        $guard = new \OCA\Mcp\Access\Visibility\VisibilityGuard($nativeConfig, $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class));
        $privacy = new \OCA\Mcp\Access\Logs\LogFileNames((new InMemoryAppConfig())->worker($this), $guard);
        $log = new \OCA\Mcp\Tests\Support\Logs\FakeFileLog(\OCA\Mcp\Tests\Support\Logs\FakeFileLog::lines(1, static fn (): array => ['message' => 'synthetic-private']));
        $nativeFactory = $this->createMock(\OCP\Log\ILogFactory::class);
        $nativeFactory->method('get')->with('file')->willReturn($log);
        $events = [];
        $auditDown = false;
        $dispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(static function ($event) use (&$events, &$auditDown): void {
            if ($auditDown) { throw new \RuntimeException('synthetic audit outage'); }
            $events[] = $event;
        });
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $services = [IConfig::class => $nativeConfig, \OCA\Mcp\Access\Logs\LogsAccess::class => $access,
            \OCA\Mcp\Access\Logs\LogFileNames::class => $privacy, \OCP\Log\ILogFactory::class => $nativeFactory,
            \OCP\EventDispatcher\IEventDispatcher::class => $dispatcher, \Psr\Log\LoggerInterface::class => $logger];
        $built = $resolved = [];
        $container = $this->createMock(ContainerInterface::class);
        $get = function (string $id) use (&$get, &$built, &$resolved, $services, $factories, $container): object {
            if (isset($built[$id])) { return $built[$id]; }
            $resolved[] = $id;
            if (isset($services[$id])) { return $built[$id] = $services[$id]; }
            if (isset($factories[$id])) { return $built[$id] = $factories[$id]($container); }
            self::assertStringStartsWith('OCA\\Mcp\\Modules\\Logs\\', $id, 'Unexpected dependency in Logs composition');
            $reflection = new \ReflectionClass($id);
            $arguments = [];
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    throw new \LogicException('Expected an explicitly typed service dependency');
                }
                $arguments[] = $get($type->getName());
            }
            return $built[$id] = $reflection->newInstanceArgs($arguments);
        };
        $container->method('get')->willReturnCallback($get);
        $module = $get(\OCA\Mcp\Modules\Logs\LogsModule::class);
        self::assertCount(2, $module->definitions());
        self::assertCount(6, $module->guideNotes());
        self::assertSame([\OCA\Mcp\Modules\Logs\LogsModule::class], $resolved);
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->expects(self::never())->method('isEnabledForUser');
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, $this->createMock(\OCP\IUserManager::class));
        $registry = new \OCA\Mcp\Mcp\Tools\ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $logger));
        self::assertSame([], $registry->definitions('root'), 'The default read grant stays denied even to admins');
        self::assertSame([\OCA\Mcp\Modules\Logs\LogsModule::class, \OCA\Mcp\Access\Logs\LogsAccess::class], $resolved);
        self::assertSame([], $events);
        $reject = function (array $args) use ($registry): void {
            try { $registry->call('logs_list', $args, 'root'); self::fail('Expected registry refusal'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        };
        $reject([]);
        $policy->setGrant('root', 'logs', 'read', true);
        $allowed = false;
        $reject([]);
        $allowed = true;
        self::assertSame(['logs_list', 'logs_analyze'], array_column($registry->definitions('root'), 'name'));
        $reject(['limit' => '2']);
        self::assertSame(['denied', 'denied', 'invalid'], array_map(static fn ($event) => $event->getParameters()['outcome'], $events));
        self::assertSame([], $log->calls);
        self::assertNotContains(\OCA\Mcp\Modules\Logs\Reading\LogQuery::class, $resolved);
        self::assertNotContains(\OCA\Mcp\Modules\Logs\Tools\ListLogs::class, $resolved);
        self::assertNotContains(\OCP\Log\ILogFactory::class, $resolved);
        self::assertSame('synthetic-private', $registry->call('logs_list', [], 'root')['structuredContent']['entries'][0]['message']);
        self::assertSame(1, $registry->call('logs_analyze', [], 'root')['structuredContent']['summary']['matched']);
        self::assertSame([[50, 0], [5000, 0]], $log->calls);
        $guard->setHiddenTagIds(['7']);
        self::assertSame('[hidden]', $registry->call('logs_list', [], 'root')['structuredContent']['entries'][0]['message']);
        $privacy->setEnabled(false);
        self::assertSame('synthetic-private', $registry->call('logs_list', [], 'root')['structuredContent']['entries'][0]['message']);
        $auditDown = true;
        $result = $registry->call('logs_list', [], 'root');
        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertStringNotContainsString('synthetic-private', $result['content'][0]['text']);
        $auditDown = false;
        $config->system['log_type'] = 'syslog';
        $reads = count($log->calls);
        self::assertSame([], $registry->definitions('root'));
        $reject([]);
        self::assertCount($reads, $log->calls);
        self::assertSame('denied', $events[array_key_last($events)]->getParameters()['outcome']);
    }

}
