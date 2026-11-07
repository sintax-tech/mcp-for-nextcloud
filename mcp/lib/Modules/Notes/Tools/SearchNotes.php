<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Presentation\NotesSchema;
use OCA\Mcp\Modules\Notes\Reading\SearchNotesHandler;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `notes_search`: notes whose title or Markdown content contains a keyword, with a snippet of each. */
final class SearchNotes implements Tool {
	/**
	 * @param SearchNotesHandler $searches the search
	 */
	public function __construct(
		private SearchNotesHandler $searches,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of notes_search
	 */
	public static function definition(): ToolDefinition {
		return NotesSchema::definition('notes_search', 'read', NotesMessages::TOOL_SEARCH_DESCRIPTION, [
			'query' => ['type' => 'string', 'minLength' => 1, 'description' => NotesMessages::PARAM_SEARCH_QUERY],
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => NotesMessages::PARAM_SEARCH_LIMIT],
			'category' => NotesSchema::category(NotesMessages::PARAM_SEARCH_CATEGORY),
		], ['query']);
	}

	/**
	 * @param array{query:string, limit:int, category?:string} $arguments validated arguments, defaults applied
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the matches as JSON, with the notice of a missing notes folder
	 * @throws InvalidArgumentException on a category with traversal or control characters
	 * @throws ToolFailure for a client-safe failure of the storage
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->searches->handle($context->userId, $arguments));
	}
}
