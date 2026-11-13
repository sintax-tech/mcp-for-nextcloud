<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Modules\Files\Presentation\TaggingMessages;
use OCA\Mcp\Modules\Files\Tagging\SetTagHandler;
use OCA\Mcp\Modules\Files\Tagging\SetTagPreview;

/** Publishes files_set_tag: plans, then on confirmation changes one file-tag relation through native DAV. */
final class SetTag implements WriteTool {
	/**
	 * @param SetTagHandler $handler the confirmed relation change
	 * @param SetTagPreview $preview the plan of the change
	 */
	public function __construct(private SetTagHandler $handler, private SetTagPreview $preview) {
	}

	/** @return ToolDefinition default-denied files.tags write, published as destructive */
	public static function definition(): ToolDefinition {
		// Destructive by the plan (phase 5): a relation change can trigger tag-driven removal or access rules, and `tags` is not
		// one of ToolPresentation's destructive operations.
		return FilesSchema::definition(['name' => 'files_set_tag', 'module' => 'files', 'operation' => 'tags', 'destructiveHint' => true,
			'description' => TaggingMessages::setTagTool(),
			'inputSchema' => FilesSchema::schema([
				'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()],
				'tag_id' => ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,18}$', 'description' => TaggingMessages::tagId()],
				'tag' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => TaggingMessages::tagName()],
				'assigned' => ['type' => 'boolean', 'description' => TaggingMessages::assigned()],
				'confirm_shared' => ['type' => 'boolean', 'description' => FilesMessages::confirmShared()],
				PlanState::ARGUMENT => PlanState::property(),
			], ['path', 'assigned'])]);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments with confirm: true
	 * @param ToolContext $context actor
	 * @return array<string,mixed> the receipt, or the shared-write confirmation, as JSON
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->handler->handle($arguments, $context->userId));
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param ToolContext $context actor
	 * @return array<string,mixed> the plan, never a side effect
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->plan($arguments, $context->userId);
	}
}
