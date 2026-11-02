<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;

/**
 * Says so when a Notes read found no notes folder at all (issue #23).
 *
 * An empty list used to look the same whether the account has no notes or the folder could not be found. The answer
 * keeps its format (the first content block is still the JSON list) and gains a second text block with a notice the
 * model reads. It is fixed English, like the other notices written for the model.
 */
final class NotesFolderNotice {
	/** Read tools whose answer is a list that would otherwise come back silently empty. */
	public const TOOLS = ['notes_list', 'notes_search'];
	/** What the model is told when the notes folder does not exist. */
	public const NOTICE = 'The notes folder of this account was not found, so no notes could be read. If the user '
		. 'expects notes here, the Notes app folder may have been renamed or moved; notes_create creates the folder '
		. 'with the name the Notes app uses.';

	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * Appends the notice to a successful notes_list or notes_search answer when the notes folder does not exist.
	 *
	 * @param string $tool Tool that answered.
	 * @param Folder|null $root Notes folder, null when it does not exist.
	 * @param array{content: list<array{type:string, text:string}>, isError?: bool} $result The tool's answer.
	 * @return array{content: list<array{type:string, text:string}>, isError?: bool} The answer, with the notice when it applies.
	 * @throws \JsonException When the notice cannot be encoded.
	 */
	public static function attach(string $tool, ?Folder $root, array $result): array {
		if ($root !== null || !in_array($tool, self::TOOLS, true) || ($result['isError'] ?? false)) {
			return $result;
		}
		$result['content'][] = ToolResult::json(['notesFolderFound' => false, 'notice' => self::NOTICE])['content'][0];
		return $result;
	}
}
