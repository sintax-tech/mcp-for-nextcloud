<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Sharing\ShareAccess;
use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_list_shares tool. */
final class ListShares implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Sharing\ListSharesHandler $handler existing effect composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Sharing\ListSharesHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_list_shares', 'module' => 'files', 'operation' => 'read',
				'description' => FilesMessages::listSharesTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::listSharesPath()],
					'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ShareAccess::MAX_OFFSET, 'default' => 0,
						'description' => FilesMessages::listSharesOffset()],
				])]);
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
