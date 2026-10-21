<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Support\Dav\FakeCalendarDav;
use OCA\Mcp\Tests\Support\Dav\FakeCalendarStore;
use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates as DateInput;
use OCA\Mcp\Modules\Calendar\Events\EventBuilder;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Modules\Calendar\Relocation\EventRelocator;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Creation\EventCreator as CreateEvent;
use OCA\Mcp\Modules\Calendar\Deletion\EventDeleter as DeleteEvent;
use OCA\Mcp\Modules\Calendar\Reading\CalendarReader as ListCalendars;
use OCA\Mcp\Modules\Calendar\Reading\EventReader as ListEvents;
use OCA\Mcp\Modules\Calendar\Relocation\EventMover as MoveEvent;
use OCA\Mcp\Modules\Calendar\Relocation\EventTransfer as TransferEvent;
use OCA\Mcp\Modules\Calendar\Editing\EventEditor as UpdateEvent;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Platform\Dav\CalDav\TrashPolicy;

use OCA\Mcp\Modules\Deck\DeckModule;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\Access\ConversationResolver;
use OCA\Mcp\Modules\Talk\Messaging\MessageSender;
use OCA\Mcp\Modules\Talk\Attachments\FileSharer;
use OCA\Mcp\Modules\Talk\Groups\GroupCreation;
use OCA\Mcp\Modules\Talk\References\ReferenceLinker;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Modules\Talk\Messaging\ReplyPlan;
use OCA\Mcp\Modules\Talk\DirectMessaging\OneToOneConversation;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A failure the server refuses to run is the most read text of the app: it is what the person sees when
 * something did not work. Deck, Notes, Talk and Calendar each have one here, triggered the way the client would
 * trigger it, and it has to reach the result in the language of the account in all three languages.
 */
final class ToolErrorsLanguagesTest extends TestCase {
use \OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;

    /** `deck_read_card` against a card the Deck refuses: one message for "not found" and "no permission". */
    private const DECK = [
        'en' => 'Card, list or board not found, or no permission.',
        'pt_BR' => 'Card, lista ou quadro não encontrado ou sem permissão.',
        'es' => 'Tarjeta, lista o tablero no encontrado, o sin permiso.',
    ];

    /** `talk_read_messages` against a conversation the caller is not part of. */
    private const TALK = [
        'en' => 'conversation not found or not accessible',
        'pt_BR' => 'conversa não encontrada ou sem acesso',
        'es' => 'conversación no encontrada o sin acceso',
    ];

    /** `notes_read` against a note id that does not exist. */
    private const NOTES_NOT_FOUND = [
        'en' => 'Resource not found in Nextcloud.',
        'pt_BR' => 'Recurso não encontrado no Nextcloud.',
        'es' => 'Recurso no encontrado en Nextcloud.',
    ];

    /** `notes_delete` where the trash bin would not take the note back. */
    private const NOTES = [
        'en' => 'Deletion blocked: the trash bin (files_trashbin) is not active for this note, so it would not be recoverable.',
        'pt_BR' => 'Exclusão bloqueada: a lixeira (files_trashbin) não está ativa para esta nota, então ela não seria recuperável.',
        'es' => 'Eliminación bloqueada: la papelera (files_trashbin) no está activa para esta nota, por lo que no sería recuperable.',
    ];

    /** `calendar_list_events` against a missing calendar. */
    private const CALENDAR_NOT_FOUND = [
        'en' => 'Calendar or event not found.',
        'pt_BR' => 'Calendário ou evento não encontrado.',
        'es' => 'Calendario o evento no encontrado.',
    ];

    protected function tearDown(): void {
        Translator::reset();
    }

    public function testADeckFailureReachesTheClientInTheLanguageOfTheAccount(): void {
        foreach (self::DECK as $language => $expected) {
            Translator::use(new JsonL10n($language));
            $capabilities = $this->deckDoubles();
            $capabilities->cards->method('findCard')->willThrowException(new DoesNotExistException('card 7 is gone'));
            $module = $this->deckModuleFixture($capabilities, $this->appManager(), users: $this->userManager());

            $this->assertSame($expected, $this->call($module, 'deck_read_card', ['cardId' => 7]), $language);
        }
    }

    public function testATalkFailureReachesTheClientInTheLanguageOfTheAccount(): void {
        foreach (self::TALK as $language => $expected) {
            Translator::use(new JsonL10n($language));
            $this->assertSame($expected, $this->call($this->talkModule(), 'talk_read_messages', ['conversation_token' => 'abcd1234']), $language);
        }
    }

    public function testANotesNotFoundFailureReachesTheClientInTheLanguageOfTheAccount(): void {
        foreach (self::NOTES_NOT_FOUND as $language => $expected) {
            Translator::use(new JsonL10n($language));
            $this->assertSame($expected, $this->call($this->notesModule(), 'notes_read', ['id' => 9999]), $language);
        }
    }

    public function testANotesFailureReachesTheClientInTheLanguageOfTheAccount(): void {
        foreach (self::NOTES as $language => $expected) {
            Translator::use(new JsonL10n($language));
            $this->assertSame($expected, $this->call($this->notesModule(), 'notes_delete', ['id' => 1, 'confirm' => true]), $language);
        }
    }

    public function testACalendarFailureReachesTheClientInTheLanguageOfTheAccount(): void {
        foreach (self::CALENDAR_NOT_FOUND as $language => $expected) {
            Translator::use(new JsonL10n($language));
            $this->assertSame($expected, $this->call($this->calendarModule(), 'calendar_list_events', ['calendar' => '/remote.php/dav/calendars/alice/missing/']), $language);
        }
    }

    /**
     * Runs one tool call through the registry, exactly as the protocol does, and returns the text the client
     * receives, so what is asserted here is the message that reaches the person and not the exception behind it.
     *
     * @return string text of the error result
     */
    private function call(ToolModule $module, string $name, array $arguments): string {
        $config = new InMemoryConfig();
        $policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        foreach (GrantPolicy::CATALOG as $grantedModule => $operations) {
            foreach ($operations as $operation) {
                $policy->setGrant('alice', $grantedModule, $operation, true);
            }
        }
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $this->appManager(), $this->userManager());
        $registry = new ToolRegistry([$module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->createMock(LoggerInterface::class)));
        $result = $registry->call($name, $arguments, 'alice');
        $this->assertArrayHasKey('isError', $result, $name . ' must be refused as an error result');
        return (string)$result['content'][0]['text'];
    }

    /** Every app is on but the trash bin, which is the condition the Notes refusal names. */
    private function appManager(): IAppManager {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(
            static fn (string $app, ?IUser $user = null): bool => $app !== 'files_trashbin',
        );
        return $apps;
    }

    private function userManager(): IUserManager {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        return $users;
    }

    private function notesModule(): NotesModule {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->createMock(Folder::class));
        $repository = new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($root, $this->createMock(IConfig::class));
        return \OCA\Mcp\Tests\Support\Notes\NotesTestFactory::module($this, [\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class => $repository, \OCA\Mcp\Modules\Notes\Storage\NoteLookup::class => new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(null), \OCA\Mcp\Modules\Notes\Storage\NoteMetadata::class => new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata(null), \OCA\Mcp\Modules\Notes\Storage\NoteNames::class => new \OCA\Mcp\Modules\Notes\Storage\NoteNames(), \OCA\Mcp\Modules\Notes\Content\NoteContent::class => new \OCA\Mcp\Modules\Notes\Content\NoteContent(), \OCP\App\IAppManager::class => $this->appManager(), \OCP\IUserManager::class => $this->userManager(), \OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class => $this->createMock(SharedWriteGuard::class), \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class => $this->createMock(NodeAccessInfo::class), \OCA\Mcp\Access\Visibility\VisibilityGuard::class => null, \OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite::class => null]);
    }

    /**
     * The Talk services blow up the way a missing or unreachable conversation does, so the refusal is built by
     * the resolver itself instead of being written into a mock.
     */
    private function talkModule(): TalkModule {
        $services = \OCA\Mcp\Tests\Support\Talk\TalkNativeFixtures::create(fn (string $class) => $this->createMock($class));
        $services->manager->method('forUser')->willThrowException(new \RuntimeException('spreed is unreachable'));
        $resolver = new ConversationResolver($services->manager->forUser(...), $services->participantService->forUser(...), $services->roomService->forUser(...), $services->conversationConstants->forUser(...));
        $reader = new ConversationReader($services->manager->forUser(...), $services->participantService->forUser(...), $services->chatManager->forUser(...), $resolver, $this->createMock(ActorNames::class), \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));
        return \OCA\Mcp\Tests\Support\Talk\TalkModuleFixtures::create([
            \OCA\Mcp\Modules\Talk\Reading\ConversationReader::class => $reader,
            \OCA\Mcp\Modules\Talk\Access\ConversationResolver::class => $resolver,
            \OCA\Mcp\Modules\Talk\Messaging\MessageSender::class => $this->createMock(MessageSender::class),
            \OCA\Mcp\Modules\Talk\Attachments\FileSharer::class => $this->createMock(FileSharer::class),
            \OCA\Mcp\Modules\Talk\Messaging\ReplyPlan::class => $this->createMock(ReplyPlan::class),
            \OCA\Mcp\Modules\Talk\DirectMessaging\OneToOneConversation::class => $this->createMock(OneToOneConversation::class),
            \OCA\Mcp\Modules\Talk\Groups\GroupCreation::class => $this->createMock(GroupCreation::class),
            \OCA\Mcp\Modules\Talk\References\ReferenceLinker::class => $this->createMock(ReferenceLinker::class),
            \OCA\Mcp\Modules\Talk\DirectMessaging\ContactResolver::class => $this->createMock(\OCA\Mcp\Modules\Talk\DirectMessaging\ContactResolver::class),
            \OCA\Mcp\Modules\Talk\DirectMessaging\DirectMessagePlan::class => $this->createMock(\OCA\Mcp\Modules\Talk\DirectMessaging\DirectMessagePlan::class),
            \OCA\Mcp\Modules\Talk\Groups\GroupPlan::class => $this->createMock(\OCA\Mcp\Modules\Talk\Groups\GroupPlan::class),
            \OCA\Mcp\Modules\Talk\Attachments\AttachmentPublisher::class => $this->createMock(\OCA\Mcp\Modules\Talk\Attachments\AttachmentPublisher::class),
            \OCA\Mcp\Modules\Talk\Attachments\AttachPlan::class => $this->createMock(\OCA\Mcp\Modules\Talk\Attachments\AttachPlan::class),
            \OCA\Mcp\Modules\Talk\Attachments\QuotePlan::class => $this->createMock(\OCA\Mcp\Modules\Talk\Attachments\QuotePlan::class),
            \OCA\Mcp\Modules\Talk\Messaging\BatchSender::class => $this->createMock(\OCA\Mcp\Modules\Talk\Messaging\BatchSender::class),
            \OCA\Mcp\Modules\Talk\Messaging\BatchPlan::class => $this->createMock(\OCA\Mcp\Modules\Talk\Messaging\BatchPlan::class),
        ], $this->createMock(LoggerInterface::class)
        );
    }

    private function calendarModule(): CalendarModule {
        $store = new FakeCalendarStore();
        $access = \OCA\Mcp\Tests\Support\Dav\ScopeStub::access($store);
        $classification = new Classification();
        $repository = new EventRepository($store, $classification);
        $dates = new DateInput();
        $mapper = new EventMapper();
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new \DateTimeImmutable('2026-03-10T12:00:00Z'));
        $logger = $this->createMock(LoggerInterface::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $config = $this->createMock(IConfig::class);
        $listCalendars = new ListCalendars($access);
        $listEvents = new ListEvents($access, $store, $store, $repository, $classification, new EventExpander(), $mapper, $dates, $time, $logger);
        $users = $this->userManager();
        $guard = new SharedGuard($users);
        $dav = new FakeCalendarDav($store);
        $builder = new EventBuilder();
        $attendees = new AttendeeResolver($users);
        $scheduling = new Scheduling($appConfig);
        $relocator = new EventRelocator($access, $store, $dav, $repository);
        $createEvent = new CreateEvent($access, $guard, $dav, $store, $repository, $builder, $mapper, $dates, $attendees, $scheduling, $time);
        $updateEvent = new UpdateEvent($access, $guard, $dav, $store, $repository, $builder, $mapper, $dates, $attendees, $scheduling, $time);
        $moveEvent = new MoveEvent($access, $relocator, $guard);
        $deleteEvent = new DeleteEvent($access, $guard, $dav, $store, $repository, new TrashPolicy($config), $scheduling);
        $transferEvent = new TransferEvent($relocator);
        return \OCA\Mcp\Tests\Support\Calendar\CalendarModuleFactory::create($listCalendars, $listEvents, $createEvent, $updateEvent, $moveEvent, $deleteEvent, $transferEvent, new \OCA\Mcp\Modules\Calendar\Execution\EventApproval($scheduling, $builder, $guard, new \OCA\Mcp\Modules\Calendar\Scheduling\PlanWarnings(
                new \OCA\Mcp\Modules\Calendar\Scheduling\CollisionCheck($store, $store, $repository, $classification, new EventExpander(), $logger),
                new \OCA\Mcp\Modules\Calendar\Scheduling\AvailabilityCheck($this->createMock(\OCP\Calendar\IManager::class), $users, $logger),
                new \OCA\Mcp\Modules\Calendar\Scheduling\SharedCalendarFinder($access, $store, $users, $this->createMock(\OCP\IGroupManager::class)),
                $attendees,
                $builder,
                $users,
                $logger,
            )), \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));
    }
}
