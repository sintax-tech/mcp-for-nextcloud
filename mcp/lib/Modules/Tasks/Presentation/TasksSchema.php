<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Presentation;

use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Builds task definitions and repeated properties through the existing DAV schema contract. */
final class TasksSchema {
	/** Grant module of Tasks tools. */
	public const MODULE = 'tasks';
	/** Required Nextcloud app. */
	public const APP = 'dav';

	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param string $name tool name
	 * @param string $operation grant operation
	 * @param string $description English description for the model, without the plan note of a write
	 * @param array<string, array<string, mixed>> $properties input properties, in published order, without confirm_shared
	 * @param list<string> $required required properties
	 * @return ToolDefinition the definition of one Tasks tool
	 */
	public static function definition(string $name, string $operation, string $description, array $properties, array $required = []): ToolDefinition {
		$definition = DavCollectionSchema::definition(self::MODULE, self::APP, $name, $description, $operation, $properties, $required);
		return new ToolDefinition(
			name: $definition['name'],
			module: $definition['module'],
			operation: $definition['operation'],
			description: $definition['description'],
			inputSchema: $definition['inputSchema'],
			app: $definition['app'],
		);
	}

	/** @return array<string, mixed> path of a VTODO calendar from the calendar listing */
	public static function calendar(): array {
		return DavCollectionSchema::calendar('VTODO calendar path from List task calendars.');
	}

	/** @return array<string, array<string, mixed>> calendar and task identity in published order */
	public static function identity(): array {
		return ['calendar' => self::calendar(), 'uid' => DavCollectionSchema::uid()];
	}

	/** @return array<string, array<string, mixed>> editable fields in their existing published order */
	public static function fields(): array {
		return [
			'summary' => DavCollectionSchema::text('Task title. Omit fields to preserve their existing values.', 1, 1024),
			'description' => DavCollectionSchema::text('Task description.'),
			'start' => [
				'type' => ['string', 'null'],
				'description' => 'ISO date or timestamp with offset; null clears the start.',
			],
			'due' => [
				'type' => ['string', 'null'],
				'description' => 'ISO date or timestamp with offset; null clears the due date. Must use the same date type as start.',
			],
			'priority' => [
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 9,
				'description' => 'iCalendar priority: 0 undefined, 1 highest, 9 lowest.',
			],
			'percentComplete' => [
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 100,
				'description' => 'Progress percentage. 100 completes the task; a lower value reopens it.',
			],
			'categories' => [
				'type' => 'array',
				'maxItems' => \OCA\Mcp\Modules\Tasks\Composing\TaskData::MAX_CATEGORIES,
				'items' => ['type' => 'string', 'maxLength' => 1024],
				'description' => 'Free-text task categories, as in the Tasks app: 1-255 characters each after trimming spaces. '
					. 'The list replaces all existing categories; [] clears them; omit to preserve them.',
			],
			'parent' => [
				'type' => ['string', 'null'],
				'minLength' => 1,
				'maxLength' => 255,
				'description' => 'UID of the parent task, making this task its subtask as in the Tasks app. '
					. 'The parent must be in the same calendar; null makes the task top-level; omit to preserve it.',
			],
		];
	}
}
