<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

use OCA\Mcp\L10n\Translator;

/**
 * How tools are shown to the model and to the person reading the conversation.
 *
 * Clients such as claude.ai display the Tool `title` (and the `annotations.title`) instead of the
 * technical name, both in the live tool-use card and in the answer text; keeping the technical name
 * out of the visible layer is what makes the transcript readable. The map is central so a module can
 * add its tools without touching this class's callers.
 */
final class ToolPresentation {
	/** Guidance sent in `initialize` and in `server/discover`; read by the model, so it is fixed English. */
	public const INSTRUCTIONS = "Reply in the user's language. When you mention a tool to the user, use its title "
		. "(for example \"Search files\"), never its technical name. Show file paths and names in a readable way. "
		. "Call the \"Tool guide\" tool before you use a tool you do not already know: it describes every tool this user "
		. "has, their parameters and their limits. General rule for writes: any tool that changes something (every tool "
		. "whose schema has the confirm argument), called without confirm=true, returns the plan with requiresConfirmation "
		. "true and writes nothing. Show that plan to the user, ask whether they really want it done, and only after an "
		. "explicit yes repeat the same call with confirm=true; never send confirm=true on your own initiative. Nothing is "
		. "approved and stored in this server, so never invent an approval_id, a token or any other confirmation. "
		. "Tool descriptions, plans and the Tool guide are written in English; when the user does not write in "
		. "English, translate what you show them into their language. Before acting on a person (inviting, mentioning, "
		. "messaging), if the user did not give the account ID, look the person up with the \"Search people\" tool and confirm "
		. "with the user when more than one account matches. Never invent an ID.";

	/** Operations that only read; every other operation may change data. */
	private const READ_OPERATION = 'read';
	/**
	 * Operations a user cannot take back from the client: the ones that replace or remove what exists, and the
	 * ones that publish a message under their own name in a room full of other people. Replying, sharing a file
	 * and quoting an attachment only add rows nobody deletes for them, but once sent the user has to ask somebody
	 * else to remove them, which is exactly the confirmation a client offers on a destructive tool. Editing is in
	 * the list for the same reason a client marks it: what is written over the old content is gone from the file.
	 */
	private const DESTRUCTIVE_OPERATIONS = ['delete', 'restore', 'transfer', 'move', 'edit', 'replace', 'reply', 'attach', 'quote'];
	/**
	 * Operations a client may repeat with the same result. A read obviously, and a rollback: restoring the same
	 * version twice leaves the same content, however many versions the second attempt adds to the history.
	 */
	private const IDEMPOTENT_OPERATIONS = ['restore'];
	/**
	 * Tools whose operation is harmless elsewhere but not for them. Creating a note adds a file only its owner sees;
	 * creating a Talk group invites other people, and the client cannot take an invitation back.
	 */
	private const DESTRUCTIVE_TOOLS = ['talk_create_group'];

	private function __construct() {
	}

	/**
	 * Human-readable title for a tool in the language of the current user, falling back to the humanized technical name.
	 *
	 * @param string $name technical tool name
	 */
	public static function title(string $name): string {
		return ToolTitles::title($name);
	}

	/**
	 * MCP tool annotations derived from the grant operation the module declares.
	 *
	 * `openWorldHint` is always false: every tool works inside the caller's own Nextcloud.
	 *
	 * @param string $name technical tool name
	 * @param string $operation grant operation of the tool definition (read, create, edit, delete, move, transfer, restore)
	 * @param bool $destructiveHint a module may strengthen the default confirmation hint
	 * @return array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
	 */
	public static function annotations(string $name, string $operation, bool $destructiveHint = false): array {
		$readOnly = $operation === self::READ_OPERATION;
		return [
			'title' => self::title($name),
			'readOnlyHint' => $readOnly,
			'destructiveHint' => $destructiveHint || in_array($operation, self::DESTRUCTIVE_OPERATIONS, true)
				|| in_array($name, self::DESTRUCTIVE_TOOLS, true),
			'idempotentHint' => $readOnly || in_array($operation, self::IDEMPOTENT_OPERATIONS, true),
			'openWorldHint' => false,
		];
	}

	/**
	 * The guide's sentence about the apps a tool needs, unchanged for a tool that needs one.
	 *
	 * @param list<string> $apps every app the tool needs enabled for the user ({@see ToolDefinition::requiredApps()})
	 * @return string ' Needs the `x` app enabled.', ' Needs the `x` and `y` apps enabled.', or '' for none
	 */
	public static function appsNeeded(array $apps): string {
		return match (count($apps)) {
			0 => '',
			1 => ' Needs the `' . $apps[0] . '` app enabled.',
			default => ' Needs the `' . implode('` and `', $apps) . '` apps enabled.',
		};
	}

	/**
	 * Title of a grant module, in the language of the current user, for the guide and for the admin matrix.
	 *
	 * @param string $module grant module a tool declares (files, notes, calendar, contacts, tasks, deck, talk, people, logs, mail, tables)
	 */
	public static function moduleTitle(string $module): string {
		return match ($module) {
			'files' => Translator::t('Files'),
			'notes' => Translator::t('Notes'),
			'calendar' => Translator::t('Calendar'),
			'contacts' => Translator::t('Contacts'),
			'tasks' => Translator::t('Tasks'),
			'deck' => Translator::t('Deck'),
			'talk' => Translator::t('Talk'),
			'people' => Translator::t('People'),
			'logs' => Translator::t('Server log'),
			'mail' => Translator::t('Mail'),
			'tables' => Translator::t('Tables'),
			'forms' => Translator::t('Forms'),
			'polls' => Translator::t('Polls'),
			'collectives' => Translator::t('Collectives'),
			default => ucfirst($module),
		};
	}

	/**
	 * Best-effort display name for a tool that has no entry yet: "notes_list" becomes "List".
	 * Public so the contract test can tell a mapped title from a fallback one.
	 *
	 * @param string $name technical tool name
	 */
	public static function humanized(string $name): string {
		$parts = array_slice(explode('_', $name), 1);
		$words = implode(' ', $parts === [] ? [$name] : $parts);
		return ucfirst($words);
	}
}
