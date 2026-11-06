<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\VersionTools;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_versions_list tool. */
final class ListVersions implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Reading\ListVersionsHandler $handler existing effect composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Reading\ListVersionsHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		return FilesSchema::definition(['name' => 'files_versions_list', 'module' => 'files', 'operation' => 'read', 'app' => VersionTools::APP,
				'description' => FilesMessages::versionsListTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => $path,
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => VersionTools::DEFAULT_LIMIT, 'default' => VersionTools::DEFAULT_LIMIT],
				], ['path'])]);
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
