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
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Modules\Calendar\Composing\AttendeeResolver;
use OCA\Mcp\Tests\Unit\Tools\Calendar\ScopeStub;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Composing\DateInput;
use OCA\Mcp\Modules\Calendar\Composing\EventBuilder;
use OCA\Mcp\Modules\Calendar\Locating\EventExpander;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;
use OCA\Mcp\Modules\Calendar\Moving\EventRelocator;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventDraft;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventDraft;
use OCA\Mcp\Modules\Calendar\Reading\ListCalendarsHandler;
use OCA\Mcp\Modules\Calendar\Reading\ListEventsHandler;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventDraft;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventDraft;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Calendar\Writing\TrashPolicy;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\DeckModule as DeckToolModule;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;
use OCA\Mcp\Modules\Talk\Attachments\FileSharer;
use OCA\Mcp\Modules\Talk\Groups\GroupCreator;
use OCA\Mcp\Modules\Talk\ReferenceLinker;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Locating\UserConversationResolver;
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
use OCA\Mcp\Tests\Unit\Tools\Notes\NotesTestModule;

/**
 * A failure the server refuses to run is the most read text of the app: it is what the person sees when
 * something did not work. Deck, Notes, Talk and Calendar each have one here, triggered the way the client would
 * trigger it, and it has to reach the result in the language of the account in all three languages.
 */
final class ToolErrorsLanguagesTest extends TestCase {
	/** `deck_read_card` against a card the Deck refuses: one message for "not found" and "no permission". */
	private const DECK = [
		'en' => 'Card, list or board not found, or no permission.',
		'pt_BR' => 'Cartão, lista ou quadro não encontrado ou sem permissão.',
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
			$gateway = $this->createMock(DeckGatewayInterface::class);
			$gateway->method('findCard')->willThrowException(new DoesNotExistException('card 7 is gone'));
			$module = \OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestModule::build(
				$this->createMock(ContainerInterface::class),
				$this->appManager(),
				$this->createMock(LoggerInterface::class),
				$this->createMock(\OCP\IURLGenerator::class),
				$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
				$this->userManager(),
			);
			\OCA\Mcp\Tests\Unit\Tools\Deck\DeckTestModule::setGateway($module, $gateway);

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
		$policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($config->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore());
		foreach (GrantPolicy::CATALOG as $grantedModule => $operations) {
			foreach ($operations as $operation) {
				$policy->setGrant('alice', $grantedModule, $operation, true);
			}
		}
		$registry = new ToolRegistry([$module], $policy, $this->appManager(), $this->userManager(), $this->createMock(LoggerInterface::class));
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
		$repository = new NotesRepository($root, $this->createMock(IConfig::class));
		return NotesTestModule::build($repository, $this->appManager(), $this->userManager(), $this->createMock(SharedWriteGuard::class), $this->createMock(NodeAccessInfo::class));
	}

	/**
	 * The Talk services blow up the way a missing or unreachable conversation does, so the refusal is built by
	 * the resolver itself instead of being written into a mock.
	 */
	private function talkModule(): TalkModule {
		$services = $this->createMock(TalkServices::class);
		$services->method('manager')->willThrowException(new \RuntimeException('spreed is unreachable'));
		$resolver = new ConversationResolver($services);
		$reader = new ConversationReader($services, $resolver, $this->createMock(ActorNames::class), \OCA\Mcp\Tests\Unit\Access\Visibility\OutputReferenceFilters::inactive($this));
		return \OCA\Mcp\Tests\Unit\Tools\Talk\TalkTestModule::build(
			$services,
			$reader,
			$resolver,
			$this->createMock(ConversationWriter::class),
			$this->createMock(FileSharer::class),
			$this->createMock(WritePreview::class),
			$this->createMock(UserConversationResolver::class),
			$this->createMock(GroupCreator::class),
			$this->createMock(ReferenceLinker::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function calendarModule(): CalendarModule {
		$store = new FakeCalendarStore();
		$access = ScopeStub::access($store);
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
		$listCalendars = new ListCalendarsHandler($access);
		$listEvents = new ListEventsHandler($access, $store, $repository, $classification, new EventExpander(), $mapper, $dates, $time, $logger);
		$users = $this->userManager();
		$guard = new SharedGuard($users);
		$dav = new FakeCalendarDav($store);
		$builder = new EventBuilder();
		$attendees = new AttendeeResolver($users);
		$scheduling = new Scheduling($appConfig);
		$relocator = new EventRelocator($access, $store, $dav, $repository);
		$createEvent = new CreateEventDraft($access, $guard, $dav, $store, $repository, $builder, $mapper, $dates, $attendees, $scheduling, $time);
		$updateEvent = new UpdateEventDraft($access, $guard, $dav, $store, $repository, $builder, $mapper, $dates, $attendees, $scheduling, $time);
		$moveEvent = new MoveEventDraft($access, $relocator, $guard);
		$deleteEvent = new DeleteEventDraft($access, $guard, $dav, $store, $repository, new TrashPolicy($config), $scheduling);
		$transferEvent = new TransferEventDraft($relocator);
		return \OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestModule::build(
			$listCalendars,
			$listEvents,
			$createEvent,
			$updateEvent,
			$moveEvent,
			$deleteEvent,
			$transferEvent,
			new \OCA\Mcp\Modules\Calendar\Approval\CalendarDraftApproval($scheduling, $builder, $guard, new \OCA\Mcp\Modules\Calendar\Scheduling\PlanWarnings(
				new \OCA\Mcp\Modules\Calendar\Scheduling\CollisionCheck($store, $repository, $classification, new EventExpander(), $logger),
				new \OCA\Mcp\Modules\Calendar\Scheduling\AvailabilityCheck($this->createMock(\OCP\Calendar\IManager::class), $users, $logger),
				new \OCA\Mcp\Modules\Calendar\Scheduling\SharedCalendarFinder($access, $store, $users, $this->createMock(\OCP\IGroupManager::class)),
				$attendees,
				$builder,
				$users,
				$logger,
			)),
		);
	}
}
