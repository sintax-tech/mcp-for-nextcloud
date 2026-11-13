<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Presentation;

use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** The definition of the meeting routine: one closed schema, no service resolved. */
final class RoutinesSchema {
	/** Longest room name Talk's group creation accepts, which is also the event title. */
	public const TITLE_MAX = 64;
	/** Most local accounts one meeting invites, the bound of Talk's group creation and of the event guests. */
	public const PARTICIPANTS_MAX = 50;
	/** Longest agenda, the bound of one Talk message. */
	public const AGENDA_MAX = 4000;
	/** English model guidance of routines_create_meeting, shared by discovery and the guide. */
	public const MEETING_NOTE = 'Creates a meeting in three steps after one confirmation, only when the user explicitly asks for a room: '
		. 'first a new private Talk group room named title with the local participants, then the agenda posted as the first message of '
		. 'that room, and last an event in calendar with the same title, the participants as guests and the link of the new room as '
		. 'its location. Without confirm: true the answer is the plan of all three steps and nothing is created; the room link is only '
		. 'known after the room exists. The confirmed call runs the steps in order and stops at the first one that fails, is uncertain '
		. 'or leaves invitations missing; whatever was created stays and the receipt lists it (room token and link, message id, event '
		. 'UID) with what was not done. Nothing is retried, deleted or undone, so calling again creates another room: after a stopped '
		. 'receipt, ask the user and plan only the missing steps with the Talk and Calendar tools. send_invitations is false by default; '
		. 'with true Nextcloud scheduling may email the guests and delivery is not confirmed. A calendar of somebody else needs '
		. 'confirm_shared: true. Besides this permission, each step needs its own: talk.create, talk.reply and calendar.create.';
	/** English model guidance of routines_prepare_meeting, shared by discovery and the guide. */
	public const PREPARE_NOTE = 'Builds a read-only dossier of one event the actor can already see. Pass calendar and uid together to name '
		. 'the event, or omit both and pass from and to: the earliest visible occurrence that overlaps that window is used. Recurrence and '
		. 'private or confidential events stay with the calendar reader; an event it does not return is refused. Participants are addresses '
		. 'already on the occurrence, at most 10. An address becomes a local account id only with people.read and one exact match. Mail, Deck '
		. 'and files are consulted only with their own grants, and only from those addresses or from card and file references already on the '
		. 'occurrence. A source without its grant is omitted and named, and the dossier still returns. The mail lookback is 30 days, with at '
		. 'most 20 messages, 20 cards, 20 files and 20 mail folders. Partial coverage is marked. Nothing is saved or written.';

	/** Static schema only. */
	private function __construct() {
	}

	/** @return ToolDefinition the default-denied routines.meeting write */
	public static function meetingDefinition(): ToolDefinition {
		$account = ['type' => 'string', 'minLength' => 1, 'maxLength' => 64];
		return new ToolDefinition('routines_create_meeting', 'routines', 'meeting', self::MEETING_NOTE, [
			'type' => 'object',
			'additionalProperties' => false,
			'properties' => [
				'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::TITLE_MAX, 'description' => 'Name of the new Talk room and title of the event.'],
				'participants' => ['type' => 'array', 'items' => $account, 'minItems' => 1, 'maxItems' => self::PARTICIPANTS_MAX, 'uniqueItems' => true,
					'description' => 'Local account IDs invited to the room and to the event, besides the user.'],
				'agenda' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::AGENDA_MAX, 'description' => 'Agenda posted as the first message of the room.'],
				'calendar' => DavCollectionSchema::calendar(),
				'start' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 40, 'description' => 'Start of the event, ISO 8601 date and time.'],
				'end' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 40, 'description' => 'End of the event, ISO 8601 date and time.'],
				'timeZone' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'IANA time zone of start and end, when they carry no offset.'],
				'description' => ['type' => 'string', 'minLength' => 0, 'maxLength' => 65536, 'description' => 'Description of the event.'],
				'send_invitations' => ['type' => 'boolean', 'default' => false, 'description' => 'Whether Nextcloud scheduling sends the event invitations to the participants.'],
				'confirm_shared' => ['type' => 'boolean', 'default' => false, 'description' => 'Must be true when calendar belongs to somebody else.'],
			],
			'required' => ['title', 'participants', 'agenda', 'calendar', 'start', 'end'],
		], destructiveHint: true, apps: ['spreed', 'calendar']);
	}

	/** @return ToolDefinition the default-denied routines.prepare read */
	public static function prepareDefinition(): ToolDefinition {
		$when = ['type' => 'string', 'minLength' => 10, 'maxLength' => 40, 'description' => 'Bound of the window, ISO 8601.'];
		return new ToolDefinition('routines_prepare_meeting', 'routines', 'read', self::PREPARE_NOTE, [
			'type' => 'object',
			'additionalProperties' => false,
			'properties' => [
				'from' => $when,
				'to' => $when,
				'calendar' => DavCollectionSchema::calendar(),
				'uid' => DavCollectionSchema::uid(),
			],
			'required' => ['from', 'to'],
		], app: 'calendar', grantAnyOf: ['prepare']);
	}
}
