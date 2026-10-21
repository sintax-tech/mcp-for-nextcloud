<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\References;

use OCA\Mcp\Access\Guests\GuestRestrictions;
use InvalidArgumentException;
use OCA\Mcp\Modules\Calendar\Contracts\EventReferenceNotFound;
use OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader;
use OCA\Mcp\Modules\Deck\Contracts\CardReferenceReader;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Messaging\MessageText;
use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCA\Mcp\Access\Grants\GrantPolicy;
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
class ReferenceLinker
{
    public const TYPE_DECK_CARD = 'deck_card';
    public const TYPE_CALENDAR_EVENT = 'calendar_event';

    /** Longest event UID accepted; real UIDs are far shorter, the cap only bounds the payload. */
    public const MAX_UID_LENGTH = 255;
    /** Longest calendar path accepted, the same bound the calendar tools use for a path. */
    public const MAX_CALENDAR_LENGTH = 1000;

    public function __construct(
        private CardReferenceReader $deck,
        private EventReferenceReader $events,
        private IAppManager $appManager,
        private IUserManager $userManager,
        private GrantPolicy $grants,
        private IURLGenerator $urlGenerator,
        private ?GuestRestrictions $guests = null,
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
    public function resolve(string $userId, array $reference): array
    {
        return match ($reference['type'] ?? null) {
            self::TYPE_DECK_CARD => $this->deckCard($userId, $reference),
            self::TYPE_CALENDAR_EVENT => $this->calendarEvent($userId, $reference),
            default => throw new InvalidArgumentException(ReferenceMessages::invalidReference()),
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
    public static function append(string $message, array $item): string
    {
        $label =
            $item['type'] === self::TYPE_DECK_CARD
                ? ReferenceMessages::referenceLabelDeck()
                : ReferenceMessages::referenceLabelCalendar();
        // The body is normalized on its own first, so a blank message is still a blank message with a link.
        $body = MessageText::normalizeMessage($message);

        return MessageText::normalizeMessage($body . "\n\n" . $label . ': ' . $item['title'] . "\n" . $item['url']);
    }

    /**
     * @param array<string, mixed> $reference Reference of type deck_card
     * @return array{type:string, title:string, url:string}
     */
    private function deckCard(string $userId, array $reference): array
    {
        $cardId = $reference['card_id'] ?? null;
        if (!is_int($cardId) || $cardId < 1 || isset($reference['calendar']) || isset($reference['uid'])) {
            throw new InvalidArgumentException(ReferenceMessages::invalidReference());
        }
        $boardId = $reference['board_id'] ?? null;
        if ($boardId !== null && (!is_int($boardId) || $boardId < 1)) {
            throw new InvalidArgumentException(ReferenceMessages::invalidReference());
        }
        $this->assertModuleUsable($userId, 'deck', 'deck', ReferenceMessages::referenceDeckOff());

        try {
            // The same read the deck_read_card tool does: CardService::find() checks the permission and refuses a
            // deleted card, and the board lookup runs its own read check before it answers.
            $card = $this->deck->read($userId, $cardId);
            $actualBoard = $card['boardId'];
        } catch (Throwable $e) {
            throw new ConversationAccessException(ReferenceMessages::referenceNotFound(), $e);
        }
        // A board id that does not match is a card the caller did not mean; saying so would confirm where it is.
        if ($boardId !== null && $boardId !== $actualBoard) {
            throw new ConversationAccessException(ReferenceMessages::referenceNotFound());
        }

        return [
            'type' => self::TYPE_DECK_CARD,
            'title' => self::oneLine($card['title']),
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
    private function calendarEvent(string $userId, array $reference): array
    {
        $path = $reference['calendar'] ?? null;
        $uid = $reference['uid'] ?? null;
        if (
            !is_string($path) ||
            $path === '' ||
            !is_string($uid) ||
            trim($uid) === '' ||
            isset($reference['card_id']) ||
            isset($reference['board_id'])
        ) {
            throw new InvalidArgumentException(ReferenceMessages::invalidReference());
        }
        $this->assertModuleUsable($userId, 'calendar', 'calendar', ReferenceMessages::referenceCalendarOff());

        try {
            $item = $this->events->read($userId, $path, $uid);
        } catch (EventReferenceNotFound $e) {
            throw new ConversationAccessException(ReferenceMessages::referenceNotFound(), $e->getPrevious());
        }
        return [
            'type' => self::TYPE_CALENDAR_EVENT,
            'title' => self::oneLine($item['title'] === '' ? ReferenceMessages::referenceUntitled() : $item['title']),
            'url' => $item['url'],
        ];
    }

    /**
     * @throws ConversationAccessException When the app is off for the user or the MCP may not read that module
     */
    private function assertModuleUsable(string $userId, string $module, string $app, string $message): void
    {
        $user = $this->userManager->get($userId);
        if (
            $user === null ||
            !AppEnablement::forUser($this->appManager, $app, $user) ||
            !$this->grants->granted($userId, $module, 'read') ||
            ($this->guests !== null && !$this->guests->allowsApp($userId, $app))
        ) {
            throw new ConversationAccessException($message);
        }
    }

    /** A title is one line of the message; a newline in it would make the link look like part of the title. */
    private static function oneLine(string $title): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $title));
    }
}
