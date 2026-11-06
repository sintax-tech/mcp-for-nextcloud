<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Composing;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Presentation\AlarmSchema;

/** Validates direct draft calls with the same contract as MCP discovery. */
final class AlarmInput {
	/**
	 * @param mixed $value supplied reminder list
	 * @return list<array{minutesBefore:int, description?:string}> validated input, without coercion
	 * @throws CalendarArgumentException on invalid input
	 */
	public static function read(mixed $value): array {
		try {
			return ArgumentValidator::validate([
				'type' => 'object', 'properties' => ['alarms' => AlarmSchema::property()],
				'additionalProperties' => false,
			], ['alarms' => $value])['alarms'];
		} catch (ArgumentValidationException $e) {
			throw new CalendarArgumentException($e->clientMessage());
		}
	}
}
