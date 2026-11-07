<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk;
use OCA\Mcp\Modules\Talk\{Failures\ConversationAccessException, Writing\ConversationWriter, Presentation\Messages};
use InvalidArgumentException;
use OCA\Mcp\Access\References\ReadableDeckCard;
use OCA\Mcp\Access\References\ReadableCalendarEvent;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Grants\GrantMatrix;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUserManager;

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

	/**
	 * @param ReadableDeckCard $deck the owning module's permission-checked card projection
	 * @param ReadableCalendarEvent $calendarEvents the owning module's scoped, classified event projection
	 * @param IAppManager $appManager existing per-user app enablement
	 * @param IUserManager $userManager existing authenticated account lookup
	 * @param GrantPolicy $grants existing read grants
	 * @param IURLGenerator $urlGenerator existing canonical app URLs
	 * @param GuestRestrictions|null $guests existing optional Guests app restriction
	 */
	public function __construct(
		private ReadableDeckCard $deck,
		private ReadableCalendarEvent $calendarEvents,
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
		$label = $item['type'] === self::TYPE_DECK_CARD ? \OCA\Mcp\Modules\Talk\Presentation\TalkReferenceLabels::referenceLabelDeck() : \OCA\Mcp\Modules\Talk\Presentation\TalkReferenceLabels::referenceLabelCalendar();
		// The body is normalized on its own first, so a blank message is still a blank message with a link.
		$body = ConversationWriter::normalizeMessage($message);

		return ConversationWriter::normalizeMessage($body . "\n\n" . $label . ': ' . $item['title'] . "\n" . $item['url']);
	}

	/**
	 * @param array<string, mixed> $reference Reference of type deck_card
	 * @param string $userId authenticated caller
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
		$this->assertModuleUsable($userId, 'deck', GrantMatrix::MODULE_APPS['deck'], Messages::referenceDeckOff());

		$read = $this->deck->read($userId, $cardId, $boardId);
		if ($read->item === null) {
			throw new ConversationAccessException(Messages::referenceNotFound(), $read->failure);
		}
		$item = $read->item;

		return [
			'type' => self::TYPE_DECK_CARD,
			'title' => self::oneLine($item['title']),
			'url' => $this->urlGenerator->linkToRouteAbsolute('deck.page.indexCard', [
				'boardId' => $item['boardId'],
				'cardId' => $cardId,
			]),
		];
	}

	/**
	 * @param array<string, mixed> $reference Reference of type calendar_event
	 * @param string $userId authenticated caller
	 * @return array{type:string, title:string, url:string}
	 */
	private function calendarEvent(string $userId, array $reference): array {
		$path = $reference['calendar'] ?? null;
		$uid = $reference['uid'] ?? null;
		if (!is_string($path) || $path === '' || !is_string($uid) || trim($uid) === ''
			|| isset($reference['card_id']) || isset($reference['board_id'])) {
			throw new InvalidArgumentException(Messages::invalidReference());
		}
		$this->assertModuleUsable($userId, 'calendar', GrantMatrix::MODULE_APPS['calendar'], Messages::referenceCalendarOff());

		$read = $this->calendarEvents->read($userId, $path, $uid);
		if ($read->item === null) {
			throw new ConversationAccessException(Messages::referenceNotFound(), $read->failure);
		}
		$item = $read->item;
		$title = $item['title'];

		// The deep link the server's own event search builds: the Calendar app opens the object from the base64
		// of its DAV URL, and it works for shared calendars because the path is the caller's own view of it.
		$davUrl = $this->urlGenerator->getWebroot() . $item['path'] . rawurlencode($item['uri']);

		return [
			'type' => self::TYPE_CALENDAR_EVENT,
			'title' => self::oneLine($title === '' ? \OCA\Mcp\Modules\Talk\Presentation\TalkReferenceLabels::referenceUntitled() : $title),
			'url' => $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute('calendar.view.index') . 'edit/' . base64_encode($davUrl),
			),
		];
	}

	/**
	 * @param string $userId authenticated caller
	 * @param string $module referenced MCP module
	 * @param string $app native app identifier
	 * @param string $message existing public refusal message
	 * @return void
	 * @throws ConversationAccessException When the app is off for the user or the MCP may not read that module
	 */
	private function assertModuleUsable(string $userId, string $module, string $app, string $message): void {
		$user = $this->userManager->get($userId);
		if ($user === null || !AppEnablement::forUser($this->appManager, $app, $user) || !$this->grants->granted($userId, $module, 'read')
			|| ($this->guests !== null && !$this->guests->allowsApp($userId, $app))) {
			throw new ConversationAccessException($message);
		}
	}

	/**
	 * A title is one line of the message; a newline in it would make the link look like part of the title.
	 * @param string $title native item title
	 * @return string title with whitespace collapsed into one message line
	 */
	private static function oneLine(string $title): string {
		return trim((string)preg_replace('/\s+/u', ' ', $title));
	}
}
