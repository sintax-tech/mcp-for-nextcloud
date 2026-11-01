<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Shared JSON Schema fragments, definition envelope and result encoding of the calendar tools.
 */
final class ToolSchema {
	/** Grant module of every calendar tool. */
	public const MODULE = \OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema::MODULE;
	/** Nextcloud app that must be enabled for the user. */
	public const APP = \OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema::APP;

	/**
	 * @param string $name tool name
	 * @param string $description English source text shown to calendar-tool clients
	 * @param string $operation grant operation
	 * @param array<string, array<string, mixed>> $properties JSON Schema properties
	 * @param list<string> $required required property names
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
	 */
	public static function definition(string $name, string $description, string $operation, array $properties, array $required = []): array {
		return \OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder::definition(self::MODULE, self::APP, $name, $description, $operation, $properties, $required);
	}

	/**
	 * @param string $description English source text shown to calendar-tool clients
	 * @return array<string, mixed> calendar path property
	 */
	public static function calendar(string $description = CalendarMessages::PROP_CALENDAR): array {
		return \OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema::calendar($description);
	}

	/**
	 * @return array<string, mixed> event UID property
	 */
	public static function uid(): array {
		return \OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema::uid();
	}

	/**
	 * @return array<string, mixed> optional ETag property for optimistic concurrency
	 */
	public static function etag(): array {
		return \OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema::etag();
	}

	/**
	 * @param string $description English source text shown to calendar-tool clients
	 * @return array<string, mixed> date or timestamp property
	 */
	public static function date(string $description): array {
		return \OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema::date($description);
	}

	/**
	 * @param string $description English source text shown to calendar-tool clients
	 * @param int $minLength minimum length
	 * @param int $maxLength maximum length
	 * @return array<string, mixed> text property
	 */
	public static function text(string $description, int $minLength, int $maxLength): array {
		return \OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder::text($description, $minLength, $maxLength);
	}

	/**
	 * @param bool $allowEmpty update accepts [] to remove every attendee
	 * @return array<string, mixed> guest list property: internal account ids only
	 */
	public static function attendees(bool $allowEmpty = false): array {
		return \OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema::attendees($allowEmpty);
	}

	/**
	 * @return array<string, mixed> opt-in invitation property, defaulting to sending nothing
	 */
	public static function sendInvitations(): array {
		return \OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema::sendInvitations();
	}

	/**
	 * @param mixed ...$blocks values encoded as indented JSON, one text block each
	 * @return array{content: list<array{type:string, text:string}>} MCP result
	 */
	public static function result(mixed ...$blocks): array {
		return \OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder::result(...$blocks);
	}

	/**
	 * @param string $message translated user-facing error message
	 * @return array{content: list<array{type:string, text:string}>, isError: bool} MCP error result
	 */
	public static function error(string $message): array {
		return \OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder::error($message);
	}
}
