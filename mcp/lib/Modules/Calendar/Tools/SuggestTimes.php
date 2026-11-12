<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Calendar\Presentation\SuggestionSchema;
use OCA\Mcp\Modules\Calendar\Reading\SuggestTimesHandler;

/** Read-only bounded meeting candidates, separate from the explicit-window check. */
final class SuggestTimes implements Tool {
	/** @param SuggestTimesHandler $suggestions one page of guarded reads */
	public function __construct(private SuggestTimesHandler $suggestions) {}

	/** @return ToolDefinition published candidate search */
	public static function definition(): ToolDefinition {
		return SuggestionSchema::definition();
	}

	/**
	 * @param array<string, mixed> $arguments validated search and optional continuation index
	 * @param ToolContext $context authenticated actor
	 * @return array<string, mixed> suggestions and bounded coverage, never a reservation
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->suggestions->handle($context->userId, $arguments);
	}
}
