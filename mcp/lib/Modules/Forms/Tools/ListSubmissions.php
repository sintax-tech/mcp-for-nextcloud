<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Tools;

use OCA\Mcp\Modules\Forms\Reading\ListSubmissionsHandler;
use OCA\Mcp\Modules\Forms\Presentation\FormsSchema;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Publishes forms_list_submissions and delegates its original behavior. */
final class ListSubmissions implements Tool {
	/**
	 * @param ListSubmissionsHandler $handler the tool effect
	 */
	public function __construct(private ListSubmissionsHandler $handler) {
	}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return FormsSchema::definition('forms_list_submissions');
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
