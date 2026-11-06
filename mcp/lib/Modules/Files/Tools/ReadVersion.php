<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Integration\VersionTools;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes files_version_read and delegates to the component that reads historical file text with version metadata. */
final class ReadVersion implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Integration\ReadVersionHandler $handler executor that reads historical file text with version metadata
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Integration\ReadVersionHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		$version = ['type' => 'string', 'minLength' => 1, 'description' => \OCA\Mcp\Modules\Files\Presentation\VersionMessages::version()];
		return FilesSchema::definition(['name' => 'files_version_read', 'module' => 'files', 'operation' => 'read', 'app' => VersionTools::APP,
				'description' => \OCA\Mcp\Modules\Files\Presentation\VersionMessages::versionReadTool(),
				'inputSchema' => FilesSchema::schema(['path' => $path, 'version' => $version], ['path', 'version'])]);
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
