<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Modules\Files\Presentation\TaggingMessages;
use OCA\Mcp\Modules\Files\Tagging\ListTagsHandler;

/** Publishes files_list_tags and delegates to the component that lists the visible tags of a visible file. */
final class ListTags implements Tool {
	/**
	 * @param ListTagsHandler $handler executor that reads the file's tag relation through native DAV
	 */
	public function __construct(private ListTagsHandler $handler) {
	}

	/** @return ToolDefinition static definition, under the existing files.read grant */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_list_tags', 'module' => 'files', 'operation' => 'read',
			'description' => TaggingMessages::listTagsTool(),
			'inputSchema' => FilesSchema::schema(['path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()]], ['path'])]);
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> the path and its visible tags
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
