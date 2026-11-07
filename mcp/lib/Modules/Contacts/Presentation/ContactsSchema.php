<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Presentation;

use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/**
 * The definitions the Contacts tools publish and the schema fragments several of them repeat. Every definition comes
 * from the shared DAV collection schema, which also adds the plan note and `confirm_shared` to a write; `confirm`
 * itself is published by the registry.
 */
final class ContactsSchema {
	/** Grant module of Contacts tools. */
	public const MODULE = 'contacts';
	/** Required Nextcloud app. */
	public const APP = 'contacts';

	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param string $name tool name
	 * @param string $operation grant operation
	 * @param string $description English description for the model, without the plan note of a write
	 * @param array<string, array<string, mixed>> $properties input properties, in published order, without confirm_shared
	 * @param list<string> $required required properties
	 * @return ToolDefinition the definition of one Contacts tool
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

	/** @return array<string, mixed> the address-book path of a contact tool */
	public static function book(): array {
		return DavCollectionSchema::text('Address book path returned by List address books.', 1, 1024);
	}

	/** @return array<string, mixed> the contact object URI */
	public static function uri(): array {
		return DavCollectionSchema::text('Contact object URI returned by Search contacts.', 1, 255);
	}

	/** @return array<string, mixed> the ETag that prevents overwriting a newer change */
	public static function etag(): array {
		return DavCollectionSchema::etag();
	}

	/** @return array<string, array<string, mixed>> the editable contact fields, in published order */
	public static function fields(): array {
		$fields = [];
		foreach (['name', 'organization', 'title', 'note', 'url'] as $field) {
			$fields[$field] = DavCollectionSchema::text(
				'Contact ' . $field . '. Omit to preserve its existing value.',
				$field === 'name' ? 1 : 0,
				$field === 'note' ? 65536 : 1024
			);
		}
		$fields['organization']['description'] = 'Organization company name (first ORG component); '
			. 'existing departments and units are preserved.';
		foreach (['emails', 'phones'] as $field) {
			$fields[$field] = [
				'type' => 'array',
				'maxItems' => 50,
				'items' => DavCollectionSchema::text('Value.', 1, 1024),
				'description' => 'Replace the ' . $field . ' in order; existing parameters are preserved. '
					. 'Omit to keep all values, [] to clear them.',
			];
		}
		return $fields;
	}
}
