<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Platform\FileAccess\SharePermission;
use OCA\Mcp\Modules\Files\Sharing\ShareWriter;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_share tool. */
final class ShareFile implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Sharing\ShareFileHandler $handler existing effect composition
	 * @param \OCA\Mcp\Modules\Files\Sharing\ShareFilePreview $plans distinct plan composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Sharing\ShareFileHandler $handler, private \OCA\Mcp\Modules\Files\Sharing\ShareFilePreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		return FilesSchema::definition(['name' => 'files_share', 'module' => 'files', 'operation' => 'share', 'grantAnyOf' => ['share', 'link'],
				'destructiveHint' => true,
				'description' => FilesMessages::shareTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => $path,
					'with' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::shareWithParam()],
					'permission' => ['type' => 'string', 'enum' => SharePermission::LEVELS, 'default' => SharePermission::VIEW,
						'description' => FilesMessages::sharePermissionParam()],
					'expires' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::shareExpiresParam()],
					'note' => ['type' => 'string', 'maxLength' => ShareWriter::NOTE_MAX, 'description' => FilesMessages::shareNoteParam()],
					'password' => ['type' => 'boolean', 'description' => FilesMessages::sharePasswordParam()],
					PlanState::ARGUMENT => PlanState::property(),
				], ['path', 'with'])]);
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
