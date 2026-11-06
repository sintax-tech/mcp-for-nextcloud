<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\ImageTools;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_image_view tool. */
final class ViewImage implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Reading\ViewImageHandler $handler existing effect composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Reading\ViewImageHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		return FilesSchema::definition(['name' => 'files_image_view', 'module' => 'files', 'operation' => 'read',
				'description' => FilesMessages::imageViewTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => $path,
					'max_size' => ['type' => 'integer', 'minimum' => ImageTools::MIN_MAX_SIZE, 'maximum' => ImageTools::MAX_MAX_SIZE,
						'default' => ImageTools::DEFAULT_MAX_SIZE, 'description' => FilesMessages::imageMaxSize()],
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
