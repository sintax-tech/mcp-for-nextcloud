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
use OCA\Mcp\Modules\Files\Commenting\AddCommentHandler;
use OCA\Mcp\Modules\Files\Commenting\AddCommentPlan;
use OCA\Mcp\Modules\Files\Commenting\AddCommentPreview;
use OCA\Mcp\Modules\Files\Presentation\CommentingMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;

/** Publishes files_add_comment: plans, then on confirmation adds one comment through native DAV. */
final class AddComment implements WriteTool {
	/**
	 * @param AddCommentHandler $handler the confirmed comment
	 * @param AddCommentPreview $preview the plan of the comment
	 */
	public function __construct(private AddCommentHandler $handler, private AddCommentPreview $preview) {
	}

	/** @return ToolDefinition default-denied files.comments write */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => AddCommentPlan::TOOL, 'module' => 'files', 'operation' => 'comments',
			'description' => CommentingMessages::addCommentTool(),
			'inputSchema' => FilesSchema::schema([
				'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()],
				'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => AddCommentPlan::MAX_LENGTH, 'description' => CommentingMessages::text()],
				'confirm_shared' => ['type' => 'boolean', 'description' => FilesMessages::confirmShared()],
				PlanState::ARGUMENT => PlanState::property(),
			], ['path', 'text'])]);
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
