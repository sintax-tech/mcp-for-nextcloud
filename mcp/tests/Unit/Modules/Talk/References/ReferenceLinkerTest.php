<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk\References;

use InvalidArgumentException;
use OCA\Deck\Db\Card;
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Deck\Contracts\CardReferenceReader;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Messaging\MessageSender;
use OCA\Mcp\Modules\Talk\References\ReferenceLinker;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

class ReferenceLinkerTest extends TestCase
{
    private const CALENDAR_PATH = '/remote.php/dav/calendars/alice/pessoal/';

    private CardReferenceReader&MockObject $deck;
    private CalendarAccess&MockObject $calendars;
    private CalendarStore&MockObject $store;
    private IAppManager&MockObject $appManager;
    private GrantPolicy $grants;
    private IUserManager $users;
    private IURLGenerator $urls;
    /** @var list<string> apps enabled for the user */
    private array $enabledApps = ['deck', 'calendar'];
    private ReferenceLinker $linker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deck = $this->createMock(CardReferenceReader::class);
        $this->calendars = $this->createMock(CalendarAccess::class);
        $this->store = $this->createMockForIntersectionOfInterfaces([CalendarStore::class, \OCA\Mcp\Platform\Dav\CalDav\CollectionStore::class]);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->appManager
            ->method('isEnabledForUser')
            ->willReturnCallback(fn(string $app): bool => in_array($app, $this->enabledApps, true));
        $users = $this->users = $this->createMock(IUserManager::class);
        $users
            ->method('get')
            ->willReturnCallback(fn(string $uid): ?IUser => $uid === 'alice' ? $this->createMock(IUser::class) : null);
        $this->grants = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy(
            (new InMemoryConfig())->mock($this),
            new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore(),
        );

        $urls = $this->urls = $this->createMock(IURLGenerator::class);
        $urls
            ->method('linkToRouteAbsolute')
            ->willReturnCallback(
                static fn(string $route, array $parameters): string => $route === 'deck.page.indexCard'
                    ? 'https://cloud.example/apps/deck/board/' .
                        $parameters['boardId'] .
                        '/card/' .
                        $parameters['cardId']
                    : 'unexpected route',
            );
        $urls
            ->method('linkToRoute')
            ->willReturnCallback(
                static fn(string $route): string => $route === 'calendar.view.index'
                    ? '/apps/calendar/'
                    : 'unexpected route',
            );
        $urls
            ->method('getAbsoluteURL')
            ->willReturnCallback(static fn(string $url): string => 'https://cloud.example' . $url);
        $urls->method('getWebroot')->willReturn('');

        $classification = new Classification();
        $this->linker = new ReferenceLinker(
            $this->deck,
            new \OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader(
                $this->calendars,
                $this->store,
                new EventRepository($this->store, $classification),
                $classification,
                $urls,
            ),
            $this->appManager,
            $users,
            $this->grants,
            $urls,
        );
    }

    private function givenCard(int $cardId, string $title, int $boardId): void
    {
        $this->deck
            ->method('read')
            ->with('alice', $cardId)
            ->willReturn([
                'cardId' => $cardId,
                'boardId' => $boardId,
                'title' => $title,
            ]);
    }

    private function givenCalendar(string $ownerId = 'alice'): Calendar
    {
        $calendar = new Calendar(
            3,
            'pessoal',
            'Pessoal',
            $ownerId,
            'principals/users/' . $ownerId,
            true,
            self::CALENDAR_PATH,
        );
        $this->calendars->method('resolve')->with('alice', self::CALENDAR_PATH)->willReturn($calendar);

        return $calendar;
    }

    private function givenEvent(string $uid, string $summary, ?string $class = null): void
    {
        $data =
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\n" .
            "DTSTAMP:20260901T100000Z\r\nDTSTART:20261001T130000Z\r\nDTEND:20261001T140000Z\r\nSUMMARY:$summary\r\n" .
            ($class === null ? '' : "CLASS:$class\r\n") .
            "END:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->store
            ->method('objectByUid')
            ->with(3, $uid)
            ->willReturn([
                'id' => 9,
                'uri' => 'reuniao.ics',
                'etag' => '"e1"',
                'data' => $data,
                'deleted' => false,
            ]);
    }

    public function testACardIsLinkedByItsCanonicalUrlWithItsTitle(): void
    {
        $this->givenCard(7, 'Proposta ACME', 4);

        $this->assertSame(
            [
                'type' => 'deck_card',
                'title' => 'Proposta ACME',
                'url' => 'https://cloud.example/apps/deck/board/4/card/7',
            ],
            $this->linker->resolve('alice', [
                'type' => 'deck_card',
                'card_id' => 7,
            ]),
        );
    }

    public function testACardTheCallerCannotReadIsRefusedWithoutItsTitle(): void
    {
        $this->deck->method('read')->willThrowException(new NoPermissionException('Permission denied'));

        try {
            $this->linker->resolve('alice', [
                'type' => 'deck_card',
                'card_id' => 7,
            ]);
            $this->fail('a card out of reach was linked');
        } catch (ConversationAccessException $e) {
            // The same answer as a card that does not exist: the reference must not confirm the card is there.
            $this->assertSame(
                \OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceNotFound(),
                $e->getMessage(),
            );
        }
    }

    public function testABoardThatDoesNotHoldTheCardIsTheSameRefusal(): void
    {
        $this->givenCard(7, 'Proposta ACME', 4);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceNotFound());

        $this->linker->resolve('alice', [
            'type' => 'deck_card',
            'card_id' => 7,
            'board_id' => 5,
        ]);
    }

    public function testAMatchingBoardIsAccepted(): void
    {
        $this->givenCard(7, 'Proposta ACME', 4);

        $item = $this->linker->resolve('alice', [
            'type' => 'deck_card',
            'card_id' => 7,
            'board_id' => 4,
        ]);

        $this->assertSame('https://cloud.example/apps/deck/board/4/card/7', $item['url']);
    }

    public function testADeckThatIsOffForTheUserIsNamedAndNothingIsRead(): void
    {
        $this->enabledApps = ['calendar'];
        $this->deck->expects($this->never())->method('read');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceDeckOff());

        $this->linker->resolve('alice', [
            'type' => 'deck_card',
            'card_id' => 7,
        ]);
    }

    public function testADeckTheMcpMayNotReadIsTheSameAsADeckThatIsOff(): void
    {
        $this->grants->setGrant('alice', 'deck', 'read', false);
        $this->deck->expects($this->never())->method('read');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceDeckOff());

        $this->linker->resolve('alice', [
            'type' => 'deck_card',
            'card_id' => 7,
        ]);
    }

    /** A guest whose Guests list lacks Deck cannot reference a card, as if Deck were off; nothing is read. */
    public function testADeckGuestsDoesNotAllowIsTheSameAsADeckThatIsOff(): void {
        $guests = $this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);
        $guests->method('allowsApp')->willReturnCallback(static fn (string $uid, string $app): bool => $app !== 'deck');
        $classification = new Classification();
        $linker = new ReferenceLinker($this->deck, new \OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader($this->calendars, $this->store, new EventRepository($this->store, $classification), $classification, $this->urls),
            $this->appManager, $this->users, $this->grants, $this->urls, $guests);
        $this->deck->expects($this->never())->method('read');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceDeckOff());

        $linker->resolve('alice', ['type' => 'deck_card', 'card_id' => 7]);
    }

    public function testAnEventIsLinkedTheWayTheServerSearchLinksIt(): void
    {
        $this->givenCalendar();
        $this->givenEvent('ev-1', 'Reunião de vendas');

        $item = $this->linker->resolve('alice', [
            'type' => 'calendar_event',
            'calendar' => self::CALENDAR_PATH,
            'uid' => 'ev-1',
        ]);

        $this->assertSame('calendar_event', $item['type']);
        $this->assertSame('Reunião de vendas', $item['title']);
        $this->assertSame(
            'https://cloud.example/apps/calendar/edit/' . base64_encode(self::CALENDAR_PATH . 'reuniao.ics'),
            $item['url'],
        );
    }

    public function testACalendarTheCallerCannotSeeIsRefusedWithoutReadingEvents(): void
    {
        $this->calendars->method('resolve')->willThrowException(CalendarException::notFound());
        $this->store->expects($this->never())->method('objectByUid');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceNotFound());

        $this->linker->resolve('alice', [
            'type' => 'calendar_event',
            'calendar' => self::CALENDAR_PATH,
            'uid' => 'ev-1',
        ]);
    }

    public function testAMissingEventIsRefused(): void
    {
        $this->givenCalendar();
        $this->store->method('objectByUid')->willReturn(null);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceNotFound());

        $this->linker->resolve('alice', [
            'type' => 'calendar_event',
            'calendar' => self::CALENDAR_PATH,
            'uid' => 'ev-1',
        ]);
    }

    public function testAPrivateEventOfSomeoneElseIsRefusedLikeAMissingOne(): void
    {
        $this->givenCalendar('bob');
        $this->givenEvent('ev-1', 'Consulta médica', 'PRIVATE');

        try {
            $this->linker->resolve('alice', [
                'type' => 'calendar_event',
                'calendar' => self::CALENDAR_PATH,
                'uid' => 'ev-1',
            ]);
            $this->fail('a private event of another owner was linked');
        } catch (ConversationAccessException $e) {
            $this->assertSame(
                \OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceNotFound(),
                $e->getMessage(),
            );
            $this->assertStringNotContainsString('Consulta', $e->getMessage());
        }
    }

    public function testAConfidentialEventOfSomeoneElseIsLinkedAsBusy(): void
    {
        $this->givenCalendar('bob');
        $this->givenEvent('ev-1', 'Negociação sigilosa', 'CONFIDENTIAL');

        $item = $this->linker->resolve('alice', [
            'type' => 'calendar_event',
            'calendar' => self::CALENDAR_PATH,
            'uid' => 'ev-1',
        ]);

        // The calendar tools show only the time of such an event, and the link must not reveal more.
        $this->assertSame('Ocupado', $item['title']);
    }

    public function testACalendarThatIsOffForTheUserIsNamed(): void
    {
        $this->enabledApps = ['deck'];
        $this->calendars->expects($this->never())->method('resolve');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceCalendarOff());

        $this->linker->resolve('alice', [
            'type' => 'calendar_event',
            'calendar' => self::CALENDAR_PATH,
            'uid' => 'ev-1',
        ]);
    }

    public function testFieldsOfTheOtherTypeAreAClientMistake(): void
    {
        foreach (
            [
                ['type' => 'deck_card'],
                ['type' => 'deck_card', 'card_id' => 7, 'uid' => 'ev-1'],
                ['type' => 'calendar_event', 'uid' => 'ev-1'],
                [
                    'type' => 'calendar_event',
                    'calendar' => self::CALENDAR_PATH,
                    'uid' => 'ev-1',
                    'card_id' => 7,
                ],
                ['type' => 'file'],
            ]
            as $reference
        ) {
            try {
                $this->linker->resolve('alice', $reference);
                $this->fail('accepted ' . json_encode($reference));
            } catch (InvalidArgumentException $e) {
                $this->assertSame(
                    \OCA\Mcp\Modules\Talk\References\ReferenceMessages::invalidReference(),
                    $e->getMessage(),
                );
            }
        }
    }

    public function testTheLinkGoesOnLinesOfItsOwnAfterTheMessage(): void
    {
        $text = ReferenceLinker::append('  veja isto  ', [
            'type' => 'deck_card',
            'title' => 'Proposta ACME',
            'url' => 'https://cloud.example/apps/deck/board/4/card/7',
        ]);

        $this->assertSame(
            "veja isto\n\nDeck card: Proposta ACME\nhttps://cloud.example/apps/deck/board/4/card/7",
            $text,
        );
    }

    public function testAMessageThatOnlyFitsWithoutTheLinkIsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Messaging\MessageMessages::messageTooLong());

        ReferenceLinker::append(str_repeat('a', \OCA\Mcp\Modules\Talk\Messaging\MessageText::MAX_MESSAGE_LENGTH), [
            'type' => 'calendar_event',
            'title' => 'Reunião',
            'url' => 'https://cloud.example/apps/calendar/edit/x',
        ]);
    }

    public function testATitleWithLineBreaksStaysOnOneLine(): void
    {
        $this->givenCard(7, "Proposta\nACME\t2026", 4);

        $this->assertSame(
            'Proposta ACME 2026',
            $this->linker->resolve('alice', [
                'type' => 'deck_card',
                'card_id' => 7,
            ])['title'],
        );
    }

    public function testAnUnreadableScopeAnswersTheSameForAnExistingAndAMissingCalendar(): void {
        $store = new \OCA\Mcp\Tests\Support\Dav\FakeCalendarStore();
        $store->addCalendar('principals/users/alice', 3, 'pessoal', 'principals/users/alice');
        $scope = (new \OCA\Mcp\Tests\Support\Dav\ScopeStub())->restrict('calendar', []);
        $scope->unavailable = true;
        $classification = new Classification();
        $linker = new ReferenceLinker($this->deck, new \OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($store, $scope), $store,
            new EventRepository($store, $classification), $classification, $this->urls), $this->appManager, $this->users, $this->grants, $this->urls);
        $outcomes = [];
        foreach ([self::CALENDAR_PATH, '/remote.php/dav/calendars/alice/missing/'] as $path) {
            try {
                $linker->resolve('alice', ['type' => 'calendar_event', 'calendar' => $path, 'uid' => 'ev-1']);
                $outcomes[] = 'linked';
            } catch (\Throwable $e) {
                $outcomes[] = [$e::class, $e->getMessage()];
            }
        }
        $this->assertSame([\OCA\Mcp\Access\Policy\PolicyUnavailable::class, 'Resource scope unavailable'], $outcomes[0]);
        $this->assertSame($outcomes[0], $outcomes[1]);
        $this->assertSame([], $store->rangesAsked);
    }

    public function testATaskUidIsRefusedExactlyLikeAMissingEvent(): void {
        $store = new \OCA\Mcp\Tests\Support\Dav\FakeCalendarStore();
        $store->addCalendar('principals/users/alice', 3, 'pessoal', 'principals/users/alice');
        $store->addObject(3, 'todo1.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:todo1\r\n"
            . "DTSTAMP:20261001T100000Z\r\nSUMMARY:A task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
        $scope = (new \OCA\Mcp\Tests\Support\Dav\ScopeStub())->restrict('calendar', ['3'])->restrict('tasks', []);
        $classification = new Classification();
        $linker = new ReferenceLinker($this->deck, new \OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($store, $scope), $store,
            new EventRepository($store, $classification), $classification, $this->urls), $this->appManager, $this->users, $this->grants, $this->urls);
        $outcomes = [];
        foreach (['todo1', 'no-such-uid'] as $uid) {
            try {
                $outcomes[] = ['linked', $linker->resolve('alice', ['type' => 'calendar_event', 'calendar' => self::CALENDAR_PATH, 'uid' => $uid])];
            } catch (\Throwable $e) {
                $outcomes[] = [$e::class, $e->getMessage()];
            }
        }
        $this->assertSame([ConversationAccessException::class, \OCA\Mcp\Modules\Talk\References\ReferenceMessages::referenceNotFound()], $outcomes[0]);
        $this->assertSame($outcomes[1], $outcomes[0]);
    }
}
