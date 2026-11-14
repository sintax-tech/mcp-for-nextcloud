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
use OCA\Mcp\Modules\Calendar\Presentation\AvailabilitySchema;
use OCA\Mcp\Modules\Calendar\Reading\CheckAvailabilityHandler;

/** Read-only free/busy for one explicit window; never reserves a meeting. */
final class CheckAvailability implements Tool {
	/** @param CheckAvailabilityHandler $availability the single-window read */
	public function __construct(private CheckAvailabilityHandler $availability) {}

	/** @return ToolDefinition published opt-in read */
	public static function definition(): ToolDefinition {
		return AvailabilitySchema::definition();
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context authenticated actor
	 * @return array<string, mixed> free/busy/unknown per supplied participant, or a safe refusal
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->availability->handle($context->userId, $arguments);
	}
}
