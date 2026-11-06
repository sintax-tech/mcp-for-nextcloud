<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\VersionTools;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes files_version_restore and delegates to the component that restores historical content through the guarded restore handler. */
final class RestoreVersion implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Editing\RestoreVersionAction $handler executor that restores historical content through the guarded restore handler
	 * @param \OCA\Mcp\Modules\Files\Editing\RestoreVersionPreview $plans read-only planner for this write operation
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Editing\RestoreVersionAction $handler, private \OCA\Mcp\Modules\Files\Editing\RestoreVersionPreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		$confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
		$version = ['type' => 'string', 'minLength' => 1, 'description' => \OCA\Mcp\Modules\Files\Presentation\VersionMessages::version()];
		return FilesSchema::definition(['name' => 'files_version_restore', 'module' => 'files', 'operation' => 'restore', 'app' => VersionTools::APP,
				'description' => \OCA\Mcp\Modules\Files\Presentation\VersionMessages::versionRestoreTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => $path,
					'version' => $version,
					'confirm_shared' => $confirmShared,
				], ['path', 'version'])]);
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
