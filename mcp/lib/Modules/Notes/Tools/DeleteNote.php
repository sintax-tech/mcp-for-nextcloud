<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Notes\Deletion\DeleteNoteHandler;
use OCA\Mcp\Modules\Notes\Deletion\DeleteNotePreview;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Presentation\NotesSchema;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `notes_delete`: one note to the trash bin, only where the trash bin takes it back. */
final class DeleteNote implements WriteTool {
	/**
	 * @param DeleteNoteHandler $deletes the deletion
	 * @param DeleteNotePreview $plans the plan of the deletion
	 */
	public function __construct(
		private DeleteNoteHandler $deletes,
		private DeleteNotePreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of notes_delete
	 */
	public static function definition(): ToolDefinition {
		return NotesSchema::definition('notes_delete', 'delete', NotesMessages::TOOL_DELETE_DESCRIPTION, [
			'id' => NotesSchema::id(),
			'etag' => NotesSchema::etag(),
			'confirm_shared' => NotesSchema::confirmShared(),
		], ['id']);
	}

	/**
	 * @param array{id:int, etag?:string, confirm_shared?:bool} $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the deletion receipt as JSON, or the shared-write confirmation without deleting
	 * @throws ToolFailure when the trash bin would not take the note back, or the note is missing, stale, locked or
	 *   not deletable
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->deletes->handle($context->userId, $arguments)));
	}

	/**
	 * @param array{id:int, etag?:string, confirm_shared?:bool} $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the note that would go to the trash bin
	 * @throws ToolFailure when the trash bin would not take the note back, or the note is missing, stale or not
	 *   deletable
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->plans->plan($context->userId, $arguments));
	}
}
