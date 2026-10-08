<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\AppInfo;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\McpProtocol;
use OCA\Mcp\Service\PromptCatalog;
use OCA\Mcp\Service\ResourceRegistry;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Calendar\CalendarModule;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckServiceGateway;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Service\Calendar\CalendarSelftestReader;
use OCA\Mcp\Service\Calendar\DavCalendarSelftestReader;
use OCA\Mcp\Tools\Calendar\DavCalendarStore;
use OCA\Mcp\Tools\Calendar\EmbeddedCalDavServerFactory;
use OCA\Mcp\Tools\Calendar\EmbeddedDavDispatcher;
use OCA\Mcp\Tools\Calendar\Session;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** App bootstrap: loads the bundled production vendor/ and wires the tool registry with its explicit module list. */
class Application extends App implements IBootstrap {
    /** App id, equal to the app directory name. */
    public const APP_ID = 'mcp';

    /** Tool modules in tools/list order; registered explicitly, no autodiscovery. */
    public const MODULES = [
        FilesModule::class,
        NotesModule::class,
        CalendarModule::class,
        \OCA\Mcp\Tools\Contacts\ContactsModule::class,
        \OCA\Mcp\Tools\Tasks\TasksModule::class,
        DeckToolModule::class,
        TalkModule::class,
        \OCA\Mcp\Tools\People\PeopleModule::class,
        \OCA\Mcp\Tools\Logs\LogsModule::class,
    ];

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    /** @param IRegistrationContext $context registration context of the app */
    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(\OCP\User\Events\UserChangedEvent::class, \OCA\Mcp\OAuth\UserRevocationListener::class);
        $context->registerEventListener(\OCP\User\Events\UserDeletedEvent::class, \OCA\Mcp\OAuth\UserRevocationListener::class);
        // Production dependencies (smalot/pdfparser) are bundled in the package's vendor/.
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        $context->registerService(CalendarSelftestReader::class, static fn (ContainerInterface $c): CalendarSelftestReader => new DavCalendarSelftestReader($c));
        $context->registerService(CalendarDav::class, static fn (ContainerInterface $c): CalendarDav => $c->get(EmbeddedDavDispatcher::class));
        $context->registerService(\OCA\Mcp\Tools\Contacts\ContactStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Tools\Contacts\DavContactStore($c));
        $context->registerService(\OCA\Mcp\Tools\Tasks\TaskStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Tools\Tasks\DavTaskStore($c));
        $context->registerService(\OCA\Mcp\Tools\Contacts\ContactDav::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Tools\Contacts\EmbeddedCardDavDispatcher(
            static fn (): \Sabre\DAV\Server => \OCA\Mcp\Tools\Contacts\EmbeddedCardDavServer::create(),
            new Session($c->get(IUserSession::class)), $c->get(\OCP\IAppConfig::class), $c->get(LoggerInterface::class),
        ));
        // DavCalendarStore resolves CalDavBackend lazily (the app container falls back to the server one) on first use.
        $context->registerService(CalendarStore::class, static fn (ContainerInterface $c): CalendarStore => new DavCalendarStore($c));
        // The dispatcher builds one embedded CalDAV server per operation, so building the module never
        // loads the DAV app; a DAV app that fails to load only breaks the calendar writes.
        $context->registerService(EmbeddedDavDispatcher::class, static fn (ContainerInterface $c): EmbeddedDavDispatcher => new EmbeddedDavDispatcher(
            // The non-public embedded server, the one that takes a principal from CustomPrincipalPlugin instead of
            // answering with principals/system/public; the factory knows the Nextcloud 31 class as well.
            static fn (): \Sabre\DAV\Server => (new EmbeddedCalDavServerFactory($c))->create(),
            new Session($c->get(IUserSession::class)),
            $c->get(IConfig::class),
            $c->get(LoggerInterface::class),
        ));
        // The Talk references read Deck cards through the same gateway the Deck tools use; it resolves Deck lazily.
        $context->registerService(DeckGatewayInterface::class, static fn (ContainerInterface $c): DeckGatewayInterface => new DeckServiceGateway(
            $c,
            $c->get(ITimeFactory::class),
            new UserTimezone($c->get(IConfig::class)),
            $c->get(\OCP\ISession::class),
        ));
        $context->registerService(ToolRegistry::class, static fn (ContainerInterface $c): ToolRegistry => new ToolRegistry(
            array_map(static fn (string $class) => $c->get($class), self::MODULES),
            $c->get(GrantPolicy::class),
            $c->get(IAppManager::class),
            $c->get(IUserManager::class),
            $c->get(LoggerInterface::class),
        ));
        $context->registerService(ResourceRegistry::class, static fn (ContainerInterface $c): ResourceRegistry => new ResourceRegistry(
            $c->get(ToolRegistry::class),
            $c->get(GrantPolicy::class),
            $c->get(IAppManager::class),
            $c->get(IUserManager::class),
            $c->get(IRootFolder::class),
            $c->get(VisibilityGuard::class),
            new TextExtractor($c->get(ITempManager::class)),
            new NotesRepository($c->get(IRootFolder::class), $c->get(IConfig::class)),
            $c->get(ToolRegistry::class)->guide(),
        ));
        $context->registerService(McpProtocol::class, static fn (ContainerInterface $c): McpProtocol => new McpProtocol(
            $c->get(ToolRegistry::class),
            $c->get(PromptCatalog::class),
            $c->get(GrantPolicy::class),
            $c->get(LoggerInterface::class),
            $c->get(ResourceRegistry::class),
        ));
    }

    /** @param IBootContext $context unused; the app needs no boot-time work */
    public function boot(IBootContext $context): void {
    }
}
