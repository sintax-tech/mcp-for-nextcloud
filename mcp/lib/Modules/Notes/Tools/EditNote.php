<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Notes\Editing\EditNoteHandler;
use OCA\Mcp\Modules\Notes\Editing\EditNotePreview;
use OCA\Mcp\Modules\Notes\Editing\NoteChange;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Presentation\NotesSchema;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `notes_edit`: a whole rewrite, an append, a replace or a new title of one note. */
final class EditNote implements WriteTool {
	/**
	 * @param EditNoteHandler $edits the edit
	 * @param EditNotePreview $plans the plan of the edit
	 */
	public function __construct(
		private EditNoteHandler $edits,
		private EditNotePreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of notes_edit
	 */
	public static function definition(): ToolDefinition {
		return NotesSchema::definition('notes_edit', 'edit', NotesMessages::TOOL_EDIT_DESCRIPTION, [
			'id' => NotesSchema::id(),
			...NoteChange::properties(),
			'content' => ['type' => 'string', 'description' => NotesMessages::PARAM_NEW_CONTENT],
			'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => NotesMessages::PARAM_NEW_TITLE],
			'etag' => NotesSchema::etag(),
			PlanState::ARGUMENT => PlanState::property(),
			'confirm_shared' => NotesSchema::confirmShared(),
		], ['id']);
	}

	/**
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the note after the change as JSON, or the shared-write confirmation without writing
	 * @throws ToolFailure when the note is missing, stale, locked, not writable or over the limit
	 * @throws FileTypeDenied when the active policy refuses the current name or the renamed basename
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->edits->handle($context->userId, $arguments)));
	}

	/**
	 * @param array<string, mixed> $arguments validated notes_edit arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the note before and after the change, or the plan of an append or a replace
	 * @throws ToolFailure when the note is missing, stale, not writable or over the limit, or the title is refused
	 * @throws FileTypeDenied when the active policy refuses a basename this edit would write or rename to
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->plans->plan($context->userId, $arguments));
	}
}
