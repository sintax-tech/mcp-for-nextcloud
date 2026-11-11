<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Calendar\Composing\AttendeeResolver;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;

/**
 * The definitions the Calendar tools publish and the schema fragments several of them repeat. The shared DAV properties
 * themselves live in the protocol layer; this class only names them for the module.
 */
final class CalendarSchema {
	/** Grant module of Calendar tools. */
	public const MODULE = 'calendar';
	/** Required Nextcloud app. */
	public const APP = 'calendar';
	/** What every write adds to its description: the plan comes first and the user approves it. */
	private const WRITE_NOTE = ' First returns a detailed plan without writing. Show it to the user and wait for explicit approval; then repeat the same arguments with confirm=true, adding the etag returned in the plan when it has one.';

	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * The definition of a Calendar tool, with the input schema every tool of the module shares: an object, no
	 * additional property, and `required` only when there is one.
	 *
	 * @param string $name tool name
	 * @param string $operation grant operation
	 * @param string $description English description for the model
	 * @param array<string, array<string, mixed>> $properties input properties, in published order
	 * @param list<string> $required required properties
	 * @param bool $destructive whether the annotations mark the tool destructive beyond its operation's default
	 * @param list<string>|null $grantAnyOf explicit alternative grants for a sensitive read
	 * @return ToolDefinition the definition of one Calendar tool
	 */
	public static function definition(string $name, string $operation, string $description, array $properties, array $required = [], bool $destructive = false, ?array $grantAnyOf = null): ToolDefinition {
		$schema = SchemaBuilder::definition(self::MODULE, self::APP, $name, $description, $operation, $properties, $required)['inputSchema'];
		return new ToolDefinition(
			name: $name,
			module: self::MODULE,
			operation: $operation,
			description: $description,
			inputSchema: $schema,
			app: self::APP,
			destructiveHint: $destructive,
			grantAnyOf: $grantAnyOf,
		);
	}

	/**
	 * The definition of a Calendar write: the shared-calendar acknowledgement as its last property, and the note that
	 * asks for the plan first at the end of its description. `confirm` itself is published by the registry.
	 *
	 * @param string $name tool name
	 * @param string $operation grant operation of the write
	 * @param string $description English description for the model, without the plan note
	 * @param array<string, array<string, mixed>> $properties input properties, in published order, without confirm_shared
	 * @param list<string> $required required properties
	 * @param bool $destructive whether the annotations mark the tool destructive beyond its operation's default
	 * @return ToolDefinition the definition of one Calendar write
	 */
	public static function write(string $name, string $operation, string $description, array $properties, array $required, bool $destructive = false): ToolDefinition {
		return self::definition($name, $operation, $description . self::WRITE_NOTE, $properties + ['confirm_shared' => self::confirmShared()], $required, $destructive);
	}

	/**
	 * @param string $description English description of this calendar property
	 * @return array<string, mixed> the calendar path property
	 */
	public static function calendar(string $description = CalendarMessages::PROP_CALENDAR): array {
		return DavCollectionSchema::calendar($description);
	}

	/**
	 * @return array<string, mixed> the event UID property
	 */
	public static function uid(): array {
		return DavCollectionSchema::uid();
	}

	/**
	 * @return array<string, mixed> the optional ETag property for optimistic concurrency
	 */
	public static function etag(): array {
		return DavCollectionSchema::etag();
	}

	/**
	 * @param string $description English description of this text property
	 * @param int $minLength minimum length
	 * @param int $maxLength maximum length
	 * @return array<string, mixed> a bounded text property
	 */
	public static function text(string $description, int $minLength, int $maxLength): array {
		return SchemaBuilder::text($description, $minLength, $maxLength);
	}

	/** @return array<string, mixed> optional reminder list shared by create and update */
	public static function alarms(): array {
		return AlarmSchema::property();
	}

	/**
	 * @return array<string, mixed> the optional `confirm_shared` property every write publishes
	 */
	public static function confirmShared(): array {
		return SharedGuard::property();
	}

	/**
	 * @param string $description English source text shown to calendar-tool clients
	 * @return array<string, mixed> date or timestamp property
	 */
	public static function date(string $description): array {
		return ['type' => 'string', 'minLength' => 10, 'maxLength' => 40, 'description' => $description];
	}

	/**
	 * @param bool $allowEmpty update accepts [] to remove every attendee
	 * @return array<string, mixed> guest list property: internal account ids only
	 */
	public static function attendees(bool $allowEmpty = false): array {
		return [
			'type' => 'array',
			'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
			'minItems' => $allowEmpty ? 0 : 1,
			'maxItems' => AttendeeResolver::MAX_ATTENDEES,
			'uniqueItems' => true,
			'description' => CalendarMessages::PROP_ATTENDEES,
		];
	}

	/**
	 * @return array<string, mixed> opt-in invitation property, defaulting to sending nothing
	 */
	public static function sendInvitations(): array {
		return [
			'type' => 'boolean',
			'default' => false,
			'description' => CalendarMessages::PROP_SEND_INVITATIONS,
		];
	}
}
