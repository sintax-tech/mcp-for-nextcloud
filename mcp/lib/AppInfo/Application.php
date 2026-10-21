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
use OCA\Mcp\Mcp\Resources\ResourceRegistry;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Deck\DeckModule;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestReader;
use OCA\Mcp\Modules\Calendar\Integration\DavCalendarSelftestReader;
use OCA\Mcp\Platform\Dav\CalDav\DavReadStore as DavCalendarStore;
use OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavServerFactory;
use OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher as EmbeddedDavDispatcher;
use OCA\Mcp\Platform\Dav\Transport\Session;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
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
        \OCA\Mcp\Modules\Contacts\ContactsModule::class,
        \OCA\Mcp\Modules\Tasks\TasksModule::class,
        DeckModule::class,
        TalkModule::class,
        \OCA\Mcp\Modules\People\PeopleModule::class,
        \OCA\Mcp\Modules\Logs\LogsModule::class,
    ];

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    /** @param IRegistrationContext $context registration context of the app */
    public function register(IRegistrationContext $context): void {
        $context->registerService(\OCA\Mcp\Access\Guests\GuestRestrictions::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Access\Guests\GuestRestrictions(
            $c->get(IAppManager::class), $c->get(\OCP\IRequest::class),
            static fn () => $c->get('OCA\\Guests\\GuestManager'),
            static fn () => $c->get('OCA\\Guests\\AppWhitelist'),
            static fn () => $c->get('OCA\\Guests\\RestrictionManager'),
            static fn () => $c->get('OCA\\Guests\\Config'),
            $c->get(LoggerInterface::class),
        ));
        $context->registerService(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Access\Policy\Transactions\PolicyTransaction(
            $c->get(\OCP\IDBConnection::class), $c->get(\OCA\Mcp\Db\PolicyStateMapper::class),
            $c->get(\OCA\Mcp\Db\PolicyEventsMapper::class), $c->get(\OCP\Config\IUserConfig::class),
            $c->get(\OCP\IAppConfig::class), $c->get(\OCA\Mcp\Access\Policy\Events\PolicyEventProcessor::class),
        ));
        $context->registerEventListener(\OCP\User\Events\UserChangedEvent::class, \OCA\Mcp\OAuth\UserRevocationListener::class);
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
        $context->registerService(\OCA\Mcp\Access\ResourceScope\ScopeReader::class, static fn (ContainerInterface $c): \OCA\Mcp\Access\ResourceScope\ScopeReader => $c->get(\OCA\Mcp\Access\Grants\GrantPolicy::class));
        // Production dependencies (smalot/pdfparser) are bundled in the package's vendor/.
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        $context->registerService(\OCA\Mcp\Modules\Talk\Access\ConversationResolver::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Access\ConversationResolver(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Manager', static fn () => $c->get('OCA\\Talk\\Manager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\RoomService', static fn () => $c->get('OCA\\Talk\\Service\\RoomService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Reading\ConversationReader((new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Manager', static fn () => $c->get('OCA\\Talk\\Manager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\ChatManager', static fn () => $c->get('OCA\\Talk\\Chat\\ChatManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Access\ConversationResolver::class),
            $c->get(\OCA\Mcp\Modules\Talk\Presentation\ActorNames::class),
            $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)));
        $context->registerService(\OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader::class));
        $context->registerService(\OCA\Mcp\Modules\Talk\Tools\ListConversations::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Tools\ListConversations(
            $c->get(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class)->listConversations(...),
            $c->get(\OCA\Mcp\Modules\Talk\Execution\ResultBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Tools\ReadMessages::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Tools\ReadMessages(
            $c->get(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class)->readMessages(...),
            $c->get(\OCA\Mcp\Modules\Talk\Execution\ResultBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Integration\TalkAvailability(
            $c->get(\OCP\App\IAppManager::class),
            $c->get(\OCP\IUserManager::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Messaging\ReplyTargetReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Messaging\ReplyTargetReader(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\ChatManager', static fn () => $c->get('OCA\\Talk\\Chat\\ChatManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Messaging\MessageSender::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Messaging\MessageSender(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\ChatManager', static fn () => $c->get('OCA\\Talk\\Chat\\ChatManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
            $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            $c->get(\OCA\Mcp\Modules\Talk\Messaging\ReplyTargetReader::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Attachments\AttachmentPublisher::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Attachments\AttachmentPublisher(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\ChatManager', static fn () => $c->get('OCA\\Talk\\Chat\\ChatManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
            $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            $c->get(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessage::class),
            $c->get(\OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Attachments\UserFileResolver::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Attachments\UserFileResolver($c->get(\OCP\Files\IRootFolder::class),
            $c->get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class),
            $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)));
        $context->registerService(\OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess($c->get(\OCP\Share\IManager::class),
            $c->get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class),
            $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)));
        $context->registerService(\OCA\Mcp\Modules\Talk\DirectMessaging\OneToOneConversation::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\DirectMessaging\OneToOneConversation(
            $c->get(\OCA\Mcp\Modules\Talk\DirectMessaging\ContactResolver::class),
            $c->get(\OCP\IUserManager::class),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\RoomService', static fn () => $c->get('OCA\\Talk\\Service\\RoomService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Groups\GroupCreation::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Groups\GroupCreation(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\RoomService', static fn () => $c->get('OCA\\Talk\\Service\\RoomService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
            $c->get(\OCP\IUserManager::class),
            $c->get(\OCA\Mcp\Modules\Talk\DirectMessaging\ContactResolver::class),
            $c->get(\Psr\Log\LoggerInterface::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\TalkModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\TalkModule(
            [
                \OCA\Mcp\Modules\Talk\Tools\ListConversations::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\ListConversations::class),
                \OCA\Mcp\Modules\Talk\Tools\ReadMessages::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\ReadMessages::class),
                \OCA\Mcp\Modules\Talk\Tools\Reply::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\Reply::class),
                \OCA\Mcp\Modules\Talk\Tools\AttachFile::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\AttachFile::class),
                \OCA\Mcp\Modules\Talk\Tools\QuoteFile::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\QuoteFile::class),
                \OCA\Mcp\Modules\Talk\Tools\MessageUser::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\MessageUser::class),
                \OCA\Mcp\Modules\Talk\Tools\SendBatch::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\SendBatch::class),
                \OCA\Mcp\Modules\Talk\Tools\CreateGroup::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\CreateGroup::class),
            ],
            $c->get(\OCA\Mcp\Modules\Talk\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Talk\Execution\PreviewBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Platform\Dav\CalDav\CollectionStore::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Platform\Dav\CalDav\DavReadStore::class));
        $context->registerService(\OCA\Mcp\Platform\Dav\CalDav\CalendarAdministration::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher::class));
        $context->registerService(\OCA\Mcp\Platform\Dav\CalDav\CalendarAclProbe::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher::class));
        $context->registerService(\OCA\Mcp\Modules\Calendar\Reading\EventRangeQuery::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Calendar\Integration\DavEventRangeQuery(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class)));
        $context->registerService(\OCA\Mcp\Platform\Dav\CalDav\DavReadStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Platform\Dav\CalDav\DavReadStore(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class), static fn () => $c->get(\OCP\IDBConnection::class)));
        $context->registerService(\OCA\Mcp\Modules\Contacts\ContactsModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Contacts\ContactsModule([
            'contacts_list_addressbooks' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\ListAddressBooks::class),
            'contacts_search_contacts' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\SearchContacts::class),
            'contacts_read_contact' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\ReadContact::class),
            'contacts_create_contact' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\CreateContact::class),
            'contacts_edit_contact' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\EditContact::class),
            'contacts_delete_contact' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\DeleteContact::class),
        ]));
        $context->registerService(\OCA\Mcp\Modules\Tasks\TasksModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Tasks\TasksModule([
            'tasks_list_calendars' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\ListCalendars::class),
            'tasks_list_tasks' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\ListTasks::class),
            'tasks_read_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\ReadTask::class),
            'tasks_create_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\CreateTask::class),
            'tasks_edit_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\EditTask::class),
            'tasks_complete_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\CompleteTask::class),
            'tasks_delete_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\DeleteTask::class),
        ], static fn (string $uid) => $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)->assertAvailable($uid)));
        $context->registerService(CalendarModule::class, static fn (ContainerInterface $c) => new CalendarModule([
            'calendar_list_calendars' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\ListCalendars::class),
            'calendar_list_events' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\ListEvents::class),
            'calendar_create_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\CreateEvent::class),
            'calendar_update_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\UpdateEvent::class),
            'calendar_move_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\MoveEvent::class),
            'calendar_delete_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\DeleteEvent::class),
            'calendar_transfer_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\TransferEvent::class),
        ], static fn (string $uid) => $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)->assertAvailable($uid)));
        $context->registerService(CalendarSelftestReader::class, static fn (ContainerInterface $c): CalendarSelftestReader => new DavCalendarSelftestReader(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class)));
        $context->registerService(CalendarDav::class, static fn (ContainerInterface $c): CalendarDav => $c->get(EmbeddedDavDispatcher::class));
        $context->registerService(\OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Contacts\Integration\DavContactStore(static fn () => $c->get(\OCA\DAV\CardDAV\CardDavBackend::class)));
        $context->registerService(\OCA\Mcp\Modules\Tasks\Reading\TaskQuery::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Tasks\Integration\DavTaskQuery(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class)));
        $context->registerService(\OCA\Mcp\Platform\Dav\CardDav\ContactDav::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Platform\Dav\CardDav\EmbeddedCardDavDispatcher(
            static fn (): \Sabre\DAV\Server => (new \OCA\Mcp\Platform\Dav\CardDav\EmbeddedCardDavServer(
                static fn () => $c->get(\OCP\IURLGenerator::class),
                static fn () => $c->get(\Psr\Log\LoggerInterface::class),
                static fn () => $c->get(\OCP\IConfig::class),
                static fn () => $c->get(\OCP\L10N\IFactory::class),
                static fn () => $c->get(\OCP\EventDispatcher\IEventDispatcher::class),
                static fn () => $c->get(\OCA\DAV\CardDAV\PhotoCache::class),
                static fn () => $c->get(\OCA\DAV\CardDAV\Security\CardDavRateLimitingPlugin::class),
                static fn () => $c->get(\OCA\DAV\CardDAV\Validation\CardDavValidatePlugin::class),
                static fn () => new \OCA\DAV\AppInfo\PluginManager($c->get(\Psr\Container\ContainerInterface::class), $c->get(\OCP\App\IAppManager::class)),
            ))->create(),
            new Session($c->get(IUserSession::class)), $c->get(\OCP\IAppConfig::class), $c->get(LoggerInterface::class),
        ));
        // DavCalendarStore resolves CalDavBackend lazily (the app container falls back to the server one) on first use.
        $context->registerService(CalendarStore::class, static fn (ContainerInterface $c): CalendarStore => new DavCalendarStore(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class), static fn () => $c->get(\OCP\IDBConnection::class)));
        // The dispatcher builds one embedded CalDAV server per operation, so building the module never
        // loads the DAV app; a DAV app that fails to load only breaks the calendar writes.
        $context->registerService(EmbeddedDavDispatcher::class, static fn (ContainerInterface $c): EmbeddedDavDispatcher => new EmbeddedDavDispatcher(
            // The non-public embedded server, the one that takes a principal from CustomPrincipalPlugin instead of
            // answering with principals/system/public; the factory knows the Nextcloud 31 class as well.
            static fn (): \Sabre\DAV\Server => (new EmbeddedCalDavServerFactory(static fn () => $c->get(\OCP\IAppConfig::class), static fn () => $c->get(EmbeddedCalDavServerFactory::IMIP_PLUGIN)))->create(),
            new Session($c->get(IUserSession::class)),
            $c->get(IConfig::class),
            $c->get(LoggerInterface::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Access\DeckCaller(
            static fn () => $c->get(\OCA\Deck\Service\BoardService::class),
            $c->get(\OCP\ISession::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Contracts\CardReferenceReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\DeckCardReferenceReader($c->get(\OCA\Mcp\Modules\Deck\Reading\CardReader::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCP\IUserManager::class),
            static fn () => $c->get(\OCA\Deck\Service\AssignmentService::class),
            static fn () => $c->get(\OCA\Deck\Db\AssignmentMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\BoardReader($c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Reading\StackReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\StackReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\StackService::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Reading\CardReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\CardReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Service\CardService::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Followup\FollowupQuery::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Followup\FollowupQuery(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class),
            $c->get(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            new UserTimezone($c->get(IConfig::class)),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Cards\CardWriter::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Cards\CardWriter(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\CardService::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            new UserTimezone($c->get(IConfig::class)),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Cards\CardStateReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Cards\CardStateReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Access\OwnershipReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Access\OwnershipReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Service\BoardService::class),
            static fn () => $c->get(\OCP\IUserManager::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\BoardCreator::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\BoardCreator($c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\StackCreator::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\StackCreator(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\StackService::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\StructureStateReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\StructureStateReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\BoardMapper::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\StructureCardCounter::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\StructureCardCounter(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Access\OwnershipReader::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\EmptyStructureDeletion::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\EmptyStructureDeletion(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Structure\StructureCardCounter::class),
            $c->get(\OCA\Mcp\Modules\Deck\Structure\StructureStateReader::class),
            static fn () => $c->get(\OCA\Deck\Service\StackService::class),
            static fn () => $c->get(\OCA\Deck\Service\BoardService::class),
        ));
        $context->registerService(DeckModule::class, static fn (ContainerInterface $c): DeckModule => new DeckModule([
            'deck_list_boards' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ListBoards::class),
            'deck_list_stacks' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ListStacks::class),
            'deck_list_cards' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ListCards::class),
            'deck_read_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ReadCard::class),
            'deck_create_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\CreateCard::class),
            'deck_edit_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\EditCard::class),
            'deck_move_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\MoveCard::class),
            'deck_delete_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\DeleteCard::class),
            'deck_followup_cards' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\FollowupCards::class),
            'deck_create_board' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\CreateBoard::class),
            'deck_create_stack' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\CreateStack::class),
            'deck_delete_stack' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\DeleteStack::class),
            'deck_delete_board' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\DeleteBoard::class),
        ], $c->get(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListBoards::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ListBoards(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class)->listBoards(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListStacks::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ListStacks(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\StackReader::class)->listStacks(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListCards::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ListCards(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\CardReader::class)->listCards(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ReadCard::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ReadCard(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\CardReader::class)->findCard(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\FollowupCards::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\FollowupCards(
            $c->get(\OCA\Mcp\Modules\Deck\Followup\FollowupQuery::class)->followupCards(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Platform\FileAccess\References\FileReferenceScope::class,
            static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Access\ResourceScope\FilesReferenceScope::class));
        $context->registerService(FilesModule::class, static fn (ContainerInterface $c): FilesModule => new FilesModule([
            'files_list' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ListFiles::class),
            'files_search' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\SearchFiles::class),
            'files_tree' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ReadTree::class),
            'files_mkdir' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\MakeDirectory::class),
            'files_copy' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\CopyFile::class),
            'files_move' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\MoveFile::class),
            'files_move_batch' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\MoveBatch::class),
            'files_undo_batch' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\UndoBatch::class),
            'files_read' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ReadFile::class),
            'files_edit' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\EditFile::class),
            'files_replace' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ReplaceText::class),
            'files_checkout' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\CheckoutFile::class),
            'files_versions_list' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ListVersions::class),
            'files_version_read' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ReadVersion::class),
            'files_version_restore' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\RestoreVersion::class),
            'files_image_view' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ViewImage::class),
            'files_images_view' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ViewImages::class),
            'files_image_search' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\SearchImages::class),
            'files_list_shares' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ListShares::class),
            'files_share' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ShareFile::class),
            'files_unshare' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\UnshareFile::class),
            'files_upload' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\UploadFile::class),
            'files_create' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\CreateFile::class),
        ], static fn () => $c->get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class)->assertScopeReadable()));
        $context->registerService(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability(
            $c->get(GrantPolicy::class), $c->get(IAppManager::class), $c->get(IUserManager::class), $c->get(\OCA\Mcp\Access\Guests\GuestRestrictions::class),
        ));
        $context->registerService(\OCA\Mcp\Mcp\Tools\Execution\ToolExecutor::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor(
            $c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class), $c->get(LoggerInterface::class),
        ));
        $context->registerService(ToolRegistry::class, static fn (ContainerInterface $c): ToolRegistry => new ToolRegistry(
            array_map(static fn (string $class) => $c->get($class), self::MODULES),
            $c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class),
            $c->get(\OCA\Mcp\Mcp\Tools\Execution\ToolExecutor::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Files\Contracts\FileResourceReader::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Contracts\FileResourceReader => new \OCA\Mcp\Modules\Files\Reading\NextcloudFileResourceReader(
                $c->get(IRootFolder::class), $c->get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class), new TextExtractor($c->get(ITempManager::class)),
            ));
        $context->registerService(\OCA\Mcp\Modules\Files\Versions\VersionReader::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Versions\VersionReader => new \OCA\Mcp\Modules\Files\Versions\VersionReader(
                $c->get(IAppManager::class), $c->get(IUserManager::class), $c->get(TextExtractor::class),
                $c->get(\OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class),
                static fn () => $c->get(\OCA\Mcp\Modules\Files\Versions\VersionReader::MANAGER),
                $c->get(\OCA\Mcp\Modules\Files\Reading\OcrSupport::class),
            ));
        $context->registerService(\OCA\Mcp\Modules\Files\Images\ImageMetadata::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Images\ImageMetadata => new \OCA\Mcp\Modules\Files\Images\ImageMetadata(
                $c->get(\OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class),
                $c->get(\OCP\SystemTag\ISystemTagManager::class), $c->get(\OCP\SystemTag\ISystemTagObjectMapper::class), $c->get(IUserManager::class),
                static fn () => $c->get(\OCA\Mcp\Modules\Files\Images\ImageMetadata::METADATA_SERVICE),
            ));
        $context->registerService(\OCA\Mcp\Modules\Files\Reorganization\MoveReport::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Reorganization\MoveReport => new \OCA\Mcp\Modules\Files\Reorganization\MoveReport(
                static fn () => $c->get(\OCA\Mcp\Modules\Files\Reorganization\MoveReport::VERSIONS_MANAGER),
                $c->get(\OCP\Share\IManager::class), $c->get(IAppManager::class),
            ));
        $context->registerService(\OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader => new \OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader(new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($c->get(IRootFolder::class), $c->get(IConfig::class)), new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Content\NoteContent(), $c->get(VisibilityGuard::class)));
        $context->registerService(NotesModule::class, static fn (ContainerInterface $c): NotesModule => new NotesModule([
            'notes_list' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\ListNotes::class),
            'notes_search' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\SearchNotes::class),
            'notes_read' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\ReadNote::class),
            'notes_create' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\CreateNote::class),
            'notes_edit' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\EditNote::class),
            'notes_move' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\MoveNote::class),
            'notes_delete' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\DeleteNote::class),
        ]));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\ListNotes::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\ListNotes => new \OCA\Mcp\Modules\Notes\Tools\ListNotes($c->get(\OCA\Mcp\Modules\Notes\Reading\NoteLister::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\SearchNotes::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\SearchNotes => new \OCA\Mcp\Modules\Notes\Tools\SearchNotes($c->get(\OCA\Mcp\Modules\Notes\Search\NoteSearch::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\ReadNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\ReadNote => new \OCA\Mcp\Modules\Notes\Tools\ReadNote($c->get(\OCA\Mcp\Modules\Notes\Reading\NoteReader::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\CreateNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\CreateNote => new \OCA\Mcp\Modules\Notes\Tools\CreateNote($c->get(\OCA\Mcp\Modules\Notes\Creation\NoteCreator::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\EditNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\EditNote => new \OCA\Mcp\Modules\Notes\Tools\EditNote($c->get(\OCA\Mcp\Modules\Notes\Editing\NoteEditor::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\MoveNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\MoveNote => new \OCA\Mcp\Modules\Notes\Tools\MoveNote($c->get(\OCA\Mcp\Modules\Notes\Moving\NoteMover::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\DeleteNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\DeleteNote => new \OCA\Mcp\Modules\Notes\Tools\DeleteNote($c->get(\OCA\Mcp\Modules\Notes\Deletion\NoteDeleter::class)));
        $context->registerService(\OCA\Mcp\Modules\Logs\LogsModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Logs\LogsModule([
            'logs_list' => static fn () => $c->get(\OCA\Mcp\Modules\Logs\Tools\ListLogs::class),
            'logs_analyze' => static fn () => $c->get(\OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs::class),
        ], static fn () => $c->get(\OCA\Mcp\Access\Logs\LogsAccess::class), static fn () => $c->get(\OCA\Mcp\Modules\Logs\Audit\LogAudit::class)));
        $context->registerService(\OCA\Mcp\Modules\Logs\Tools\ListLogs::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Logs\Tools\ListLogs($c->get(\OCA\Mcp\Modules\Logs\Listing\ListEntries::class)));
        $context->registerService(\OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs($c->get(\OCA\Mcp\Modules\Logs\Analysis\AnalyzeEntries::class)));
        $context->registerService(\OCA\Mcp\Modules\People\PeopleModule::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\People\PeopleModule => new \OCA\Mcp\Modules\People\PeopleModule([
                'users_search' => static fn () => $c->get(\OCA\Mcp\Modules\People\Tools\SearchUsers::class),
            ]));
        $context->registerService(\OCA\Mcp\Modules\People\Tools\SearchUsers::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\People\Tools\SearchUsers => new \OCA\Mcp\Modules\People\Tools\SearchUsers(
                $c->get(\OCA\Mcp\Modules\People\Search\PeopleSearch::class),
            ));
        $context->registerService(ResourceRegistry::class, static fn (ContainerInterface $c): ResourceRegistry => new ResourceRegistry($c->get(ToolRegistry::class), $c->get(GrantPolicy::class), $c->get(IAppManager::class), $c->get(IUserManager::class), $c->get(\OCA\Mcp\Modules\Files\Contracts\FileResourceReader::class), $c->get(\OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader::class), $c->get(ToolRegistry::class)->guide(), $c->get(\OCA\Mcp\Access\Guests\GuestRestrictions::class)));
        $context->registerService(\OCA\Mcp\Mcp\Protocol\ToolRequests::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Protocol\ToolRequests($c->get(ToolRegistry::class)));
        $context->registerService(\OCA\Mcp\Mcp\Protocol\PromptRequests::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Protocol\PromptRequests($c->get(PromptCatalog::class), $c->get(GrantPolicy::class)));
        $context->registerService(\OCA\Mcp\Mcp\Protocol\ResourceRequests::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Protocol\ResourceRequests($c->get(ResourceRegistry::class)));
        $context->registerService(McpProtocol::class, static fn (ContainerInterface $c): McpProtocol => new McpProtocol(
            $c->get(\OCA\Mcp\Mcp\Protocol\ToolRequests::class),
            $c->get(\OCA\Mcp\Mcp\Protocol\PromptRequests::class),
            $c->get(\OCA\Mcp\Mcp\Protocol\ResourceRequests::class),
            $c->get(LoggerInterface::class),
        ));
    }

    /** @param IBootContext $context unused; the app needs no boot-time work */
    public function boot(IBootContext $context): void {
    }
}
