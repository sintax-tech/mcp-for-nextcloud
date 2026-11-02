<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Presentation\NotesSchema;
use OCA\Mcp\Modules\Notes\Reading\ListNotesHandler;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `notes_list`: every note of the user, newest first. */
final class ListNotes implements Tool {
	/**
	 * @param ListNotesHandler $lists the listing
	 */
	public function __construct(
		private ListNotesHandler $lists,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of notes_list
	 */
	public static function definition(): ToolDefinition {
		return NotesSchema::definition('notes_list', 'read', NotesMessages::TOOL_LIST_DESCRIPTION, []);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments (none)
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the notes as JSON, with the notice of a missing notes folder
	 * @throws ToolFailure for a client-safe failure of the storage
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->lists->handle($context->userId));
	}
}
