<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanText;
use Throwable;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;

/**
 * Turns the plan of a Talk write into the body a person reads before saying yes.
 *
 * The plan arrives as an array built by {@see WritePreview}, and the envelope that owns the plan text is not the
 * only thing that can read it: this class answers the plan with the conversation or the person by the name the user
 * knows them by, the text as an excerpt, and the file by its own name. A raw payload would make the user approve
 * something only the author of the tool can read, so the id of a share, of a quote or of a room never reaches the
 * visible body while a name is available.
 *
 * It reads and nothing else: no conversation is resolved, no file is looked up and no message is published, because
 * the plan already carries everything the user approves. It also never throws. A field the renderer does not
 * understand is not a failure to show, it is a reason to answer null and let the envelope fall back to its generic
 * body, so a plan this class cannot read is still shown to the user in full rather than not at all.
 */
final class TalkPlanRenderer {
	/** Longest excerpt of a message body the body carries; beyond this the text is cut and says so. */
	public const EXCERPT_MAX_LENGTH = 200;

	/**
	 * Renders one plan as Markdown.
	 *
	 * @param string $tool Name of the writing tool the plan belongs to
	 * @param array<string, mixed> $plan Plan returned by WritePreview, as the module built it
	 * @return string|null The body, or null when this class cannot read the plan
	 */
	public static function render(string $tool, array $plan): ?string {
		try {
			return match ($tool) {
				'talk_reply' => self::reply($plan),
				'talk_send_batch' => self::batch($plan),
				'talk_attach_file' => self::attach($plan),
				'talk_quote_file' => self::quote($plan),
				'talk_message_user' => self::directMessage($plan),
				'talk_create_group' => self::group($plan),
				default => null,
			};
		} catch (Throwable) {
			// The envelope catches this too, but a plan that cannot be rendered must never be the reason a
			// confirmation fails: null is the generic body, which is a worse text and still the right one.
			return null;
		}
	}

	/**
	 * A message in a conversation, with the message it answers when the reply quotes one.
	 *
	 * @param array<string, mixed> $plan Plan of talk_reply
	 * @return string|null The body, or null when the conversation or the message is missing
	 */
	private static function reply(array $plan): ?string {
		$conversation = TalkPlanValues::conversationName($plan);
		$message = TalkPlanValues::stringAt($plan, 'draft', 'message');
		if ($conversation === null || $message === null) {
			return null;
		}

		$body = [TalkPlanMessages::planToConversation($conversation), '', TalkPlanValues::excerpt($message)];

		$quoted = TalkPlanValues::quoteBlock(TalkPlanValues::valueAt($plan, 'draft', 'replyTo'));
		if ($quoted !== null) {
			$body[] = '';
			$body[] = $quoted;
		}

		$reference = TalkPlanValues::referenceLine($plan);
		if ($reference !== null) {
			$body[] = '';
			$body[] = $reference;
		}

		return implode("\n", $body);
	}

	/**
	 * A batch: how many messages go where, each one with its own excerpt and its own answer.
	 *
	 * @param array<string, mixed> $plan Plan of talk_send_batch
	 * @return string|null The body, or null when the conversation or the list of messages is missing
	 */
	private static function batch(array $plan): ?string {
		$conversation = TalkPlanValues::conversationName($plan);
		$items = TalkPlanValues::valueAt($plan, 'draft', 'messages');
		if ($conversation === null || !is_array($items) || $items === []) {
			return null;
		}

		$items = array_values($items);
		$body = [
			count($items) === 1
				? TalkPlanMessages::planOneMessage($conversation)
				: TalkPlanMessages::planMessages(count($items), $conversation),
			'',
		];

		foreach ($items as $index => $item) {
			// One unusable item makes the whole batch unreadable: showing the rest would be showing a plan the
			// confirmed call does not match.
			if (!is_array($item)) {
				return null;
			}
			$message = TalkPlanValues::stringAt($item, 'message');
			if ($message === null) {
				return null;
			}
			if ($index > 0) {
				$body[] = '';
			}
			$body[] = ($index + 1) . '. ' . TalkPlanValues::excerpt($message);

			$quoted = TalkPlanValues::quoteBlock($item['replyTo'] ?? null);
			if ($quoted !== null) {
				$body[] = '';
				$body[] = $quoted;
			}
		}

		return implode("\n", $body);
	}

	/**
	 * A file shared into a conversation, with the caption that follows it when the call carries one.
	 *
	 * @param array<string, mixed> $plan Plan of talk_attach_file
	 * @return string|null The body, or null when the conversation or the name of the file is missing
	 */
	private static function attach(array $plan): ?string {
		$conversation = TalkPlanValues::conversationName($plan);
		$file = TalkPlanValues::valueAt($plan, 'draft', 'file');
		if ($conversation === null || !is_array($file)) {
			return null;
		}
		$name = TalkPlanValues::stringAt($file, 'name');
		if ($name === null) {
			return null;
		}

		$body = [
			TalkPlanMessages::planSharingFile($name, TalkPlanValues::size($file['size'] ?? null), $conversation),
		];

		$caption = TalkPlanValues::stringAt($plan, 'draft', 'message');
		if ($caption !== null) {
			$body[] = '';
			$body[] = TalkPlanMessages::planCaptionAfterFile();
			$body[] = '';
			$body[] = TalkPlanValues::excerpt($caption);
		}

		return implode("\n", $body);
	}

	/**
	 * A message citing an attachment already in the conversation. A share whose name the plan does not carry is
	 * named by the conversation instead of by its id: approving "77" tells nobody what is about to be cited.
	 *
	 * @param array<string, mixed> $plan Plan of talk_quote_file
	 * @return string|null The body, or null when the conversation or the file block is missing
	 */
	private static function quote(array $plan): ?string {
		$conversation = TalkPlanValues::conversationName($plan);
		$file = TalkPlanValues::valueAt($plan, 'draft', 'file');
		if ($conversation === null || !is_array($file)) {
			return null;
		}

		$name = TalkPlanValues::stringAt($file, 'name');
		$body = [$name === null
			? TalkPlanMessages::planCitingUnknownFile($conversation)
			: TalkPlanMessages::planCitingFile($name, $conversation)];

		$caption = TalkPlanValues::stringAt($plan, 'draft', 'message');
		if ($caption !== null) {
			$body[] = '';
			$body[] = TalkPlanMessages::planCaptionWithCitation();
			$body[] = '';
			$body[] = TalkPlanValues::excerpt($caption);
		}

		return implode("\n", $body);
	}

	/**
	 * A direct message. The plan carries an account and no conversation, and that absence is the consequence the
	 * user has to be told about before answering.
	 *
	 * @param array<string, mixed> $plan Plan of talk_message_user
	 * @return string|null The body, or null when the person or the message is missing
	 */
	private static function directMessage(array $plan): ?string {
		$person = TalkPlanValues::stringAt($plan, 'target', 'displayName');
		$message = TalkPlanValues::stringAt($plan, 'draft', 'message');
		if ($person === null || $message === null) {
			return null;
		}

		return implode("\n", [
			TalkPlanMessages::planToPerson($person),
			'',
			TalkPlanValues::excerpt($message),
			'',
			TalkPlanMessages::planDirectConversationCreated($person),
		]);
	}

	/**
	 * A new group: its name, and everyone the call would invite.
	 *
	 * @param array<string, mixed> $plan Plan of talk_create_group
	 * @return string|null The body, or null when the name of the group is missing
	 */
	private static function group(array $plan): ?string {
		$name = TalkPlanValues::stringAt($plan, 'draft', 'name');
		$participants = TalkPlanValues::valueAt($plan, 'draft', 'participants');
		if ($name === null || !is_array($participants) || !is_array($plan['draft'] ?? null)) {
			return null;
		}

		$body = [TalkPlanMessages::planCreatingGroup($name)];

		if ($participants === []) {
			$body[] = '';
			$body[] = TalkPlanMessages::planNoGuests();

			return implode("\n", $body);
		}

		$body[] = '';
		$body[] = TalkPlanMessages::planInviting();
		foreach ($participants as $participant) {
			$displayName = is_array($participant) ? TalkPlanValues::stringAt($participant, 'displayName') : null;
			if ($displayName === null) {
				return null;
			}
			$body[] = '- ' . PlanText::strong($displayName);
		}

		return implode("\n", $body);
	}

}
