<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Tools;

use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Notes\Creation\CreateNoteHandler;
use OCA\Mcp\Modules\Notes\Creation\CreateNotePreview;
use OCA\Mcp\Modules\Notes\Presentation\NotesMessages;
use OCA\Mcp\Modules\Notes\Presentation\NotesSchema;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `notes_create`: a new note, never over an existing one. */
final class CreateNote implements WriteTool {
	/**
	 * @param CreateNoteHandler $creates the creation
	 * @param CreateNotePreview $plans the plan of the creation
	 */
	public function __construct(
		private CreateNoteHandler $creates,
		private CreateNotePreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of notes_create
	 */
	public static function definition(): ToolDefinition {
		return NotesSchema::definition('notes_create', 'create', NotesMessages::TOOL_CREATE_DESCRIPTION, [
			'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => NotesMessages::PARAM_TITLE],
			'content' => ['type' => 'string', 'default' => '', 'description' => NotesMessages::PARAM_CONTENT],
			'category' => NotesSchema::category(NotesMessages::PARAM_CATEGORY),
			'confirm_shared' => NotesSchema::confirmShared(),
		], ['title']);
	}

	/**
	 * @param array{title:string, content?:string, category?:string, confirm_shared?:bool} $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the new note as JSON, or the shared-write confirmation without creating anything
	 * @throws ToolFailure when the content is over the limit or the notes folder cannot be created
	 * @throws FileTypeDenied when the active policy refuses the projected or the final basename
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->creates->handle($context->userId, $arguments)));
	}

	/**
	 * @param array{title:string, content?:string, category?:string, confirm_shared?:bool} $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the note the call would create
	 * @throws ToolFailure when the content is over the limit or the category is below a hidden folder
	 * @throws FileTypeDenied when the active policy refuses the projected basename
	 * @throws FileWritePolicyUnavailable when the policy cannot be read
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->plans->plan($context->userId, $arguments));
	}
}
