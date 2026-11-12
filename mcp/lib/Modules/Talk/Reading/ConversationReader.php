<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Reading;
use OCA\Mcp\Modules\Talk\{Presentation\ActorNames, Failures\ConversationAccessException, Locating\ConversationResolver, Presentation\Messages, Integration\TalkServices, Integration\UnreadCounter, Integration\UnreadRoomView, Failures\TalkUnavailableException};
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Mcp\Access\Visibility\OutputReferenceFilter;
use OCP\Comments\IComment;
use Throwable;

/**
 * Read-only view of the conversations of the authenticated user, normalized into plain arrays.
 *
 * Nothing here marks anything as read, opens a lobby or renames a conversation. Reading must not change unread
 * counters, notifications, room activity or the name Talk stored.
 */
class ConversationReader {
	public const MAX_MESSAGES = 200;
	/**
	 * Comment verbs under which Talk stores its own JSON envelopes: rich objects (shares) and system messages.
	 *
	 * Talk 23 writes every comment that carries a file, voice messages and recordings included, as `object_shared`,
	 * with the kind in `parameters.metaData.messageType` (`voice-message`, `record-audio`, `record-video`). The three
	 * last verbs stay only for legacy rows, written under those verbs before the spreed migration
	 * Version14000Date20220330141647; the reader looks at the raw verb, so they match nothing else.
	 */
	private const ENVELOPE_VERBS = ['object_shared', 'system', 'voice-message', 'record-audio', 'record-video'];

	/** Default amount of messages when the caller does not ask for a number, matching the module schema. */
	public const DEFAULT_MESSAGES = 50;

	/**
	 * Keeps the native read boundary and caller-visible message projections.
	 * @param TalkServices $talkServices lazy native Talk services and per-user enablement
	 * @param ConversationResolver $resolver existing conversation access checks
	 * @param ActorNames $actorNames caller-visible participant names
	 * @param OutputReferenceFilter $references existing resource reference policy
	 * @param UnreadCounter $counter unread count Talk shows in its own list, shared with the unread listing
	 * @param UnreadRoomView $rooms lobby and display name, both read from values Talk already stored
	 */
	public function __construct(
		private TalkServices $talkServices,
		private ConversationResolver $resolver,
		private ActorNames $actorNames,
		private OutputReferenceFilter $references,
		private UnreadCounter $counter,
		private UnreadRoomView $rooms,
	) {}

	/**
	 * The conversations the user takes part in, most recently active first.
	 *
	 * @param string $userId Authenticated user
	 * @return list<array{token:string, displayName:string, type:int, participantType:int, unreadMessages:int|null, archived:bool, lastActivity:int|null}>
	 * @throws TalkUnavailableException When spreed is unavailable
	 */
	public function listConversations(string $userId): array {
		$manager = $this->talkServices->manager($userId);
		$participantService = $this->talkServices->participantService($userId);
		$chatManager = $this->talkServices->chatManager($userId);
		$markers = $this->talkServices->unreadMarkers($userId);
		$constants = $this->talkServices->conversationConstants($userId);
		$lobbyNone = $constants['lobbyNone'] ?? null;
		$ignore = $constants['lobbyIgnorePermission'] ?? null;
		$changelog = $constants['changelogType'] ?? null;
		if (!is_int($lobbyNone) || !is_int($ignore) || !is_int($changelog)) {
			throw new TalkUnavailableException();
		}

		$conversations = [];
		foreach ($manager->getRoomsForUser($userId) as $room) {
			try {
				$participant = $participantService->getParticipant($room, $userId, false);
			} catch (Throwable) {
				// A room without a readable participant is one this user cannot describe: skip it instead of
				// failing the whole listing.
				continue;
			}

			$attendee = $participant->getAttendee();
			$lastActivity = $room->getLastActivity();
			// The lobby answer is the one the unread listing already uses: decided in memory, never by opening the
			// lobby. A participant the lobby keeps out gets no count, because the number would tell what the history
			// refuses to show. The conversation stays listed. An unreadable count stays null: a missing number is
			// not the same as zero.
			$unread = $this->rooms->barred($room, $participant, $lobbyNone, $ignore)
				? 0
				: $this->unreadCount($room, $attendee, $chatManager, $userId, $markers);
			$conversations[] = [
				'token' => $room->getToken(),
				'displayName' => $this->rooms->displayName($room, $userId, $changelog, $participantService),
				'type' => $room->getType(),
				'participantType' => $attendee->getParticipantType(),
				'unreadMessages' => $unread,
				'archived' => $attendee->isArchived(),
				'lastActivity' => $lastActivity instanceof DateTimeInterface ? $lastActivity->getTimestamp() : null,
			];
		}

		usort(
			$conversations,
			static fn (array $a, array $b): int => ($b['lastActivity'] ?? 0) <=> ($a['lastActivity'] ?? 0),
		);

		return $conversations;
	}

	/**
	 * The unread count Talk would show, or null when that count cannot be read.
	 *
	 * The conversation stays in the list either way. The counter is the one shared with the unread listing: a local
	 * room is counted from the chat, a federated room from the attendee column, and nothing is written.
	 *
	 * @param object $room OCA\Talk\Room
	 * @param object $attendee OCA\Talk\Model\Attendee of the reader
	 * @param object $chatManager OCA\Talk\Chat\ChatManager
	 * @param string $userId authenticated reader
	 * @param array{unreadMigration: int, unreadFirstMessage: int} $markers Talk's special read markers
	 * @return int|null null when the counter cannot answer
	 */
	private function unreadCount(object $room, object $attendee, object $chatManager, string $userId, array $markers): ?int {
		$counted = $this->counter->count($room, $attendee, $chatManager, $userId, $markers);
		return $counted === null ? null : $counted['unread'];
	}

	/**
	 * The last messages of a conversation, oldest first, in the shape the module documents.
	 *
	 * @param string $userId Authenticated user
	 * @param string $token Conversation token
	 * @param int $limit How many messages to return, between 1 and self::MAX_MESSAGES
	 * @return list<array{id:string, type:string, text:?string, actorType:string, actorId:string, actorDisplayName:string, timestamp:int, parentId:?string, attachmentId:?int}>
	 * @throws InvalidArgumentException When the limit is out of range
	 * @throws ConversationAccessException When the conversation is missing or the user is not a participant
	 * @throws TalkUnavailableException When spreed is unavailable
	 */
	public function readMessages(string $userId, string $token, int $limit): array {
		if ($limit < 1 || $limit > self::MAX_MESSAGES) {
			throw new InvalidArgumentException(Messages::invalidLimit());
		}
		// Messages can carry attachments: an unreadable Files scope is the same 503 before the conversation is
		// looked up, whatever it holds.
		$this->references->assertAvailable($userId);

		$conversation = $this->resolver->resolveForReading($userId, $token);
		$chatManager = $this->talkServices->chatManager($userId);

		$messages = [];
		foreach ($chatManager->getHistory($conversation->room, -$limit, $limit, false) as $comment) {
			$message = $this->normalizeMessage($comment);
			// The card stays, with its type; only the id of a file the user may not reach is withheld.
			$message['attachmentId'] = $this->references->attachmentId($userId, $conversation->token(), $message['attachmentId']);
			$messages[] = $message;
		}

		return $messages;
	}

	/**
	 * A chat comment becomes one module message: a plain comment keeps its text, while a Talk system envelope keeps
	 * its type and contributes the share id so the model can quote the attachment with talk_quote_file.
	 *
	 * @param IComment $comment Chat comment as stored by Talk
	 * @return array{id:string, type:string, text:?string, actorType:string, actorId:string, actorDisplayName:string, timestamp:int, parentId:?string, attachmentId:?int}
	 */
	private function normalizeMessage(IComment $comment): array {
		$raw = (string)$comment->getMessage();
		$type = (string)$comment->getVerb();
		$text = $raw;
		$attachmentId = null;

		// Only what Talk writes itself is an envelope. Any participant can type JSON as a plain comment, and
		// reading that as an attachment would hide their text and forge the attachment id.
		$envelope = in_array($type, self::ENVELOPE_VERBS, true) ? json_decode(ltrim($raw), true) : null;
		if (is_array($envelope) && isset($envelope['message']) && is_string($envelope['message'])) {
			$type = $envelope['message'];
			$text = null;
			$share = $envelope['parameters']['share'] ?? null;
			if (is_int($share) || (is_string($share) && ctype_digit($share))) {
				$attachmentId = (int)$share;
			}
		}

		$actorId = (string)$comment->getActorId();
		$parentId = (string)$comment->getParentId();
		$created = $comment->getCreationDateTime();

		return [
			'id' => (string)$comment->getId(),
			'type' => $type,
			'text' => $text,
			'actorType' => (string)$comment->getActorType(),
			'actorId' => $actorId,
			'actorDisplayName' => $this->actorNames->displayName($actorId),
			'timestamp' => $created instanceof DateTimeInterface ? $created->getTimestamp() : 0,
			'parentId' => ($parentId === '' || $parentId === '0') ? null : $parentId,
			'attachmentId' => $attachmentId,
		];
	}
}
