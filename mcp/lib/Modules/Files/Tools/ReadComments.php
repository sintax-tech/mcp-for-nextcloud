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
use OCA\Mcp\Modules\Files\Presentation\ReadingMessages;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Modules\Files\Reading\ReadCommentsHandler;

/** Publishes files_read_comments and delegates to the component that reads one page of a visible node's comments. */
final class ReadComments implements Tool {
	/** @param ReadCommentsHandler $handler reads one native comments page */
	public function __construct(private ReadCommentsHandler $handler) {
	}

	/** @return ToolDefinition static definition, under the existing files.read grant */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_read_comments', 'module' => 'files', 'operation' => 'read',
			'description' => ReadingMessages::readCommentsTool(),
			'inputSchema' => FilesSchema::schema([
				'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => FileMetadataDav::MAX_COMMENTS,
					'default' => ReadCommentsHandler::DEFAULT_LIMIT, 'description' => ReadingMessages::commentsLimit()],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => ReadingMessages::commentsOffset()],
			], ['path'])]);
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> one page of comments
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
