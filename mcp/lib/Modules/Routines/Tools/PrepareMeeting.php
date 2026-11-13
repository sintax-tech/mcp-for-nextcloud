<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Routines\Preparation\PrepareMeetingHandler;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesSchema;

/** routines_prepare_meeting: a limited read-only dossier of one visible event. */
final class PrepareMeeting implements Tool {
	/** @param PrepareMeetingHandler $handler the dossier */
	public function __construct(private PrepareMeetingHandler $handler) {
	}

	/** @return ToolDefinition the default-denied routines.prepare read */
	public static function definition(): ToolDefinition {
		return RoutinesSchema::prepareDefinition();
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context the actor
	 * @return array<string, mixed> the dossier, never a side effect
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($context->userId, $arguments);
	}
}
