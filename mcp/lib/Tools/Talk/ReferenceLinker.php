<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\EventMapper;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\ToolSchema as CalendarSchema;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Throwable;

/**
 * Turns a reference to a Deck card or a Calendar event into a line of text a reply carries: the title and the
 * canonical link of the item, the same URL the item's own app opens and Talk previews.
 *
 * A reference is text, not a rich object. The native object_shared message needs metadata and trusted domains
 * this module has no business assembling, while a link is what a person would paste and what Talk already knows
 * how to preview. Before the link is written, the caller's access is checked through the same layers the Deck and
 * Calendar tools use, so a reference is only ever as wide as what the caller could read with those tools: an item
 * the caller cannot read is refused with the same answer as one that does not exist, and its title never reaches
 * the draft.
 *
 * The item's own module has to be usable too. A user whose Deck app is off, or whose grant does not let the MCP
 * read their cards, gets a refusal naming that, instead of a link built around a switch someone turned off.
 */
class ReferenceLinker {
    public const TYPE_DECK_CARD = 'deck_card';
    public const TYPE_CALENDAR_EVENT = 'calendar_event';

    /** Longest event UID accepted; real UIDs are far shorter, the cap only bounds the payload. */
    public const MAX_UID_LENGTH = 255;
    /** Longest calendar path accepted, the same bound the calendar tools use for a path. */
    public const MAX_CALENDAR_LENGTH = 1000;

    public function __construct(
        private DeckGatewayInterface $deck,
        private CalendarAccess $calendars,
        private CalendarStore $calendarStore,
        private EventRepository $events,
        private Classification $classification,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private GrantPolicy $grants,
        private IURLGenerator $urlGenerator,
    ) {}

    /**
     * The item a reference points at, after the caller's access to it was checked.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $reference Validated reference object of talk_reply
     * @return array{type:string, title:string, url:string}
     * @throws InvalidArgumentException When the reference is malformed for its type
     * @throws ConversationAccessException When the module is off for the user, or the item is missing or not readable
     */
    public function resolve(string $userId, array $reference): array {
        return match ($reference['type'] ?? null) {
            self::TYPE_DECK_CARD => $this->deckCard($userId, $reference),
            self::TYPE_CALENDAR_EVENT => $this->calendarEvent($userId, $reference),
            default => throw new InvalidArgumentException(Messages::invalidReference()),
        };
    }

    /**
     * The message as it will be sent: the user's text, then the item's title and link on lines of their own.
     *
     * @param string $message Message body of the call
     * @param array{type:string, title:string, url:string} $item Item resolved by resolve()
     * @return string Text to draft and send, normalized the same way a message is
     * @throws InvalidArgumentException When the message is blank or the whole text passes the message limit
     */
    public static function append(string $message, array $item): string {
        $label = $item['type'] === self::TYPE_DECK_CARD ? Messages::referenceLabelDeck() : Messages::referenceLabelCalendar();
        // The body is normalized on its own first, so a blank message is still a blank message with a link.
        $body = ConversationWriter::normalizeMessage($message);

        return ConversationWriter::normalizeMessage($body . "\n\n" . $label . ': ' . $item['title'] . "\n" . $item['url']);
    }

    /**
     * @param array<string, mixed> $reference Reference of type deck_card
     * @return array{type:string, title:string, url:string}
     */
    private function deckCard(string $userId, array $reference): array {
        $cardId = $reference['card_id'] ?? null;
        if (!is_int($cardId) || $cardId < 1 || isset($reference['calendar']) || isset($reference['uid'])) {
            throw new InvalidArgumentException(Messages::invalidReference());
        }
        $boardId = $reference['board_id'] ?? null;
        if ($boardId !== null && (!is_int($boardId) || $boardId < 1)) {
            throw new InvalidArgumentException(Messages::invalidReference());
        }
        $this->assertModuleUsable($userId, 'deck', DeckToolModule::DECK_APP, Messages::referenceDeckOff());

        try {
            // The same read the deck_read_card tool does: CardService::find() checks the permission and refuses a
            // deleted card, and the board lookup runs its own read check before it answers.
            $card = $this->deck->findCard($userId, $cardId);
            $actualBoard = $this->deck->cardBoardId($userId, $cardId);
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::referenceNotFound(), $e);
        }
        // A board id that does not match is a card the caller did not mean; saying so would confirm where it is.
        if ($boardId !== null && $boardId !== $actualBoard) {
            throw new ConversationAccessException(Messages::referenceNotFound());
        }

        return [
            'type' => self::TYPE_DECK_CARD,
            'title' => self::oneLine((string)$card->getTitle()),
            'url' => $this->urlGenerator->linkToRouteAbsolute('deck.page.indexCard', [
                'boardId' => $actualBoard,
                'cardId' => $cardId,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $reference Reference of type calendar_event
     * @return array{type:string, title:string, url:string}
     */
    private function calendarEvent(string $userId, array $reference): array {
        $path = $reference['calendar'] ?? null;
        $uid = $reference['uid'] ?? null;
        if (!is_string($path) || $path === '' || !is_string($uid) || trim($uid) === ''
            || isset($reference['card_id']) || isset($reference['board_id'])) {
            throw new InvalidArgumentException(Messages::invalidReference());
        }
        $this->assertModuleUsable($userId, 'calendar', CalendarSchema::APP, Messages::referenceCalendarOff());

        try {
            // The calendar tools' own rules: the path must be one of the caller's visible calendars, and the
            // iCalendar CLASS of an event in someone else's calendar decides what the caller may see of it.
            $calendar = $this->calendars->resolve($userId, $path);
            $row = $this->calendarStore->objectByUid($calendar->id, $uid);
            $vcalendar = ($row === null || $row['deleted']) ? null : $this->events->parse($row['data']);
        } catch (CalendarException $e) {
            throw new ConversationAccessException(Messages::referenceNotFound(), $e);
        }
        if ($row === null || $vcalendar === null) {
            throw new ConversationAccessException(Messages::referenceNotFound());
        }
        $visibility = $this->classification->visibility($vcalendar, $calendar, $userId);
        if ($visibility === Classification::HIDDEN) {
            throw new ConversationAccessException(Messages::referenceNotFound());
        }

        $summary = '';
        foreach ($vcalendar->select('VEVENT') as $event) {
            if (!isset($event->{'RECURRENCE-ID'})) {
                $summary = (string)($event->SUMMARY ?? '');
            }
        }
        // A confidential event is shown as busy by the calendar tools, and the link is not a way around that.
        $title = $visibility === Classification::BUSY ? EventMapper::BUSY_SUMMARY : $summary;

        // The deep link the server's own event search builds: the Calendar app opens the object from the base64
        // of its DAV URL, and it works for shared calendars because the path is the caller's own view of it.
        $davUrl = $this->urlGenerator->getWebroot() . $calendar->path . rawurlencode((string)$row['uri']);

        return [
            'type' => self::TYPE_CALENDAR_EVENT,
            'title' => self::oneLine($title === '' ? Messages::referenceUntitled() : $title),
            'url' => $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('calendar.view.index') . 'edit/' . base64_encode($davUrl),
            ),
        ];
    }

    /**
     * @throws ConversationAccessException When the app is off for the user or the MCP may not read that module
     */
    private function assertModuleUsable(string $userId, string $module, string $app, string $message): void {
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser($app, $user) || !$this->grants->granted($userId, $module, 'read')) {
            throw new ConversationAccessException($message);
        }
    }

    /** A title is one line of the message; a newline in it would make the link look like part of the title. */
    private static function oneLine(string $title): string {
        return trim((string)preg_replace('/\s+/u', ' ', $title));
    }
}
