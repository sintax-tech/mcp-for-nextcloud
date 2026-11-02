<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Notes\Moving\MoveNoteHandler;
use OCA\Mcp\Modules\Notes\Moving\MoveNotePreview;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Presentation\NotesSchema;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `notes_move`: one note into another category. */
final class MoveNote implements WriteTool {
	/**
	 * @param MoveNoteHandler $moves the move
	 * @param MoveNotePreview $plans the plan of the move
	 */
	public function __construct(
		private MoveNoteHandler $moves,
		private MoveNotePreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of notes_move
	 */
	public static function definition(): ToolDefinition {
		return NotesSchema::definition('notes_move', 'move', NotesMessages::TOOL_MOVE_DESCRIPTION, [
			'id' => NotesSchema::id(),
			'category' => ['type' => 'string', 'description' => NotesMessages::PARAM_CATEGORY_TARGET],
			'etag' => NotesSchema::etag(),
			'confirm_shared' => NotesSchema::confirmShared(),
		], ['id', 'category']);
	}

	/**
	 * @param array{id:int, category:string, etag?:string, confirm_shared?:bool} $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the note after the move as JSON, or the shared-write confirmation without moving
	 * @throws ToolFailure when the note is missing, stale, locked or not writable, or the title is taken in the target
	 * @throws FileTypeDenied when the active policy refuses the note's basename
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 * @throws InvalidArgumentException on a category with traversal or control characters
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->moves->handle($context->userId, $arguments)));
	}

	/**
	 * @param array{id:int, category:string, etag?:string, confirm_shared?:bool} $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> where the note goes
	 * @throws ToolFailure when the note is missing, stale or not writable, or the target is hidden
	 * @throws FileTypeDenied when the active policy refuses the note's basename
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 * @throws InvalidArgumentException on a category with traversal or control characters
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->plans->plan($context->userId, $arguments));
	}
}
