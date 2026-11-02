<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Presentation\NotesSchema;
use OCA\Mcp\Modules\Notes\Reading\ReadNoteHandler;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `notes_read`: one note, whole. */
final class ReadNote implements Tool {
	/**
	 * @param ReadNoteHandler $reads the read
	 */
	public function __construct(
		private ReadNoteHandler $reads,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of notes_read
	 */
	public static function definition(): ToolDefinition {
		return NotesSchema::definition('notes_read', 'read', NotesMessages::TOOL_READ_DESCRIPTION, ['id' => NotesSchema::id()], ['id']);
	}

	/**
	 * @param array{id:int} $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the note as JSON
	 * @throws ToolFailure when the note is missing, hidden or over the read limit
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->reads->handle($context->userId, $arguments)));
	}
}
