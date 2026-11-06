<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_unshare tool. */
final class UnshareFile implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Sharing\UnshareFileHandler $handler existing effect composition
	 * @param \OCA\Mcp\Modules\Files\Sharing\UnshareFilePreview $plans distinct plan composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Sharing\UnshareFileHandler $handler, private \OCA\Mcp\Modules\Files\Sharing\UnshareFilePreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_unshare', 'module' => 'files', 'operation' => 'share', 'grantAnyOf' => ['share', 'link'],
				'destructiveHint' => true,
				'description' => FilesMessages::unshareTool(),
				'inputSchema' => FilesSchema::schema([
					'shareId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => FilesMessages::unshareIdParam()],
					'path' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::unsharePathParam()],
					'with' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::unshareWithParam()],
					PlanState::ARGUMENT => PlanState::property(),
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
	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
