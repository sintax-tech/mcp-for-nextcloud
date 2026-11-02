<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\AppInfo;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Protocol\McpProtocol;
use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Resources\ResourceRegistry;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckServiceGateway;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Modules\Calendar\Writing\Session;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ModuleRegistrations;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
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
		\OCA\Mcp\Modules\People\PeopleModule::class,
		\OCA\Mcp\Modules\Logs\LogsModule::class,
		\OCA\Mcp\Tools\Mail\MailModule::class,
		\OCA\Mcp\Tools\Tables\TablesModule::class,
		\OCA\Mcp\Tools\Forms\FormsModule::class,
		\OCA\Mcp\Modules\Polls\PollsModule::class,
	];

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	/** @param IRegistrationContext $context registration context of the app */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(\OCP\User\Events\UserChangedEvent::class, \OCA\Mcp\OAuth\Revocation\UserRevocationListener::class);
		// Recheck causes of account deletion and group removal (contracts §3.3.2); the deletion's cut comes from its
		// account cause, so a late UserDeletedEvent never cuts a consent made after the cause was consumed.
		foreach ([\OCP\User\Events\BeforeUserDeletedEvent::class, \OCP\User\Events\UserDeletedEvent::class,
			\OCP\Group\Events\BeforeUserRemovedEvent::class, \OCP\Group\Events\UserRemovedEvent::class] as $event) {
			$context->registerEventListener($event, \OCA\Mcp\Listener\UserCauseListener::class);
		}
		// The GID lifecycle (contracts §3.3.5): pending_delete, purge by gen and by the revision read at creation.
		foreach ([\OCP\Group\Events\BeforeGroupDeletedEvent::class, \OCP\Group\Events\GroupDeletedEvent::class,
			\OCP\Group\Events\BeforeGroupCreatedEvent::class, \OCP\Group\Events\GroupCreatedEvent::class] as $event) {
			$context->registerEventListener($event, \OCA\Mcp\Listener\GroupLifecycleListener::class);
		}
		$context->registerMiddleware(\OCA\Mcp\Middleware\DeferredFlushMiddleware::class);
		// Production dependencies (smalot/pdfparser) are bundled in the package's vendor/.
		$autoload = __DIR__ . '/../../vendor/autoload.php';
		if (is_file($autoload)) {
			require_once $autoload;
		}
		$context->registerService(\OCA\Mcp\Platform\FileAccess\WriteVisibility::class, static fn (ContainerInterface $c): \OCA\Mcp\Platform\FileAccess\WriteVisibility => $c->get(VisibilityGuard::class));
		$context->registerService(\OCA\Mcp\Tools\Contacts\ContactStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Tools\Contacts\DavContactStore($c));
		$context->registerService(\OCA\Mcp\Tools\Tasks\TaskStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Tools\Tasks\DavTaskStore($c));
		$context->registerService(\OCA\Mcp\Platform\FileAccess\ActorRoot::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Platform\FileAccess\ActorRoot($c->get(\OCP\Files\IRootFolder::class), $c->get(IUserSession::class)));
		// The tools read the resource scope from the policy snapshot of their operation.
		$context->registerService(\OCA\Mcp\Access\ResourceScope\ScopeReader::class, static fn (ContainerInterface $c): \OCA\Mcp\Access\ResourceScope\ScopeReader => $c->get(\OCA\Mcp\Access\Grants\GrantPolicy::class));
		// The Files scope step of the structured-reference filter: the user's Files resource scope of the operation.
		$context->registerService(\OCA\Mcp\Access\ResourceScope\FileReferenceScope::class, static fn (ContainerInterface $c): \OCA\Mcp\Access\ResourceScope\FileReferenceScope => $c->get(\OCA\Mcp\Access\ResourceScope\FilesReferenceScope::class));
		$context->registerService(\OCA\Mcp\Tools\Contacts\ContactDav::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Tools\Contacts\EmbeddedCardDavDispatcher(
			static fn (): \Sabre\DAV\Server => \OCA\Mcp\Tools\Contacts\EmbeddedCardDavServer::create(),
			new Session($c->get(IUserSession::class)), $c->get(\OCP\IAppConfig::class), $c->get(LoggerInterface::class),
		));
		// The Talk references read Deck cards through the same gateway the Deck tools use; it resolves Deck lazily.
		$context->registerService(DeckGatewayInterface::class, static fn (ContainerInterface $c): DeckGatewayInterface => new DeckServiceGateway(
			$c,
			$c->get(ITimeFactory::class),
			new UserTimezone($c->get(IConfig::class)),
			$c->get(\OCP\ISession::class),
		));
		// Modules of the 1.3.0 contract register their own services and capability probes.
		ModuleRegistrations::register($context, self::MODULES);
		$context->registerService(ToolRegistry::class, static fn (ContainerInterface $c): ToolRegistry => new ToolRegistry(
			array_map(static fn (string $class) => $c->get($class), self::MODULES),
			$c->get(GrantPolicy::class),
			$c->get(IAppManager::class),
			$c->get(IUserManager::class),
			$c->get(LoggerInterface::class),
			$c->get(GuestRestrictions::class),
			$c->get(CapabilityRegistry::class),
		));
		$context->registerService(ResourceRegistry::class, static fn (ContainerInterface $c): ResourceRegistry => new ResourceRegistry(
			$c->get(ToolRegistry::class),
			$c->get(GrantPolicy::class),
			$c->get(IAppManager::class),
			$c->get(IUserManager::class),
			$c->get(IRootFolder::class),
			$c->get(VisibilityGuard::class),
			new TextExtractor($c->get(ITempManager::class)),
			$c->get(\OCA\Mcp\Mcp\Resources\NoteResource::class),
			$c->get(ToolRegistry::class)->guide(),
			// nc://files answers to the Files gate, the resource scope included; nc://notes keeps the plain guard (D4).
			$c->get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class),
			$c->get(GuestRestrictions::class),
		));
		$context->registerService(McpProtocol::class, static fn (ContainerInterface $c): McpProtocol => new McpProtocol(
			$c->get(ToolRegistry::class),
			$c->get(PromptCatalog::class),
			$c->get(GrantPolicy::class),
			$c->get(LoggerInterface::class),
			$c->get(IAppManager::class),
			$c->get(ResourceRegistry::class),
		));
	}

	/** @param IBootContext $context unused; the app needs no boot-time work */
	public function boot(IBootContext $context): void {
	}
}
