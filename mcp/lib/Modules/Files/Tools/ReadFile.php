<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes files_read and delegates to the component that reads visible file text with metadata and optional OCR. */
final class ReadFile implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Reading\ReadFileHandler $handler executor that reads visible file text with metadata and optional OCR
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Reading\ReadFileHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		return FilesSchema::definition(['name' => 'files_read', 'module' => 'files', 'operation' => 'read',
				'description' => \OCA\Mcp\Modules\Files\Presentation\ReadingMessages::readTool(),
				'inputSchema' => FilesSchema::schema(['path' => $path], ['path'])]);
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
