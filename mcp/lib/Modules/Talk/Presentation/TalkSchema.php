<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Talk\ReferenceLinker;
use OCA\Mcp\Modules\Talk\Locating\UserConversationResolver;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;
use stdClass;

/** The unchanged common definition and repeated properties of Talk's eight published tools. */
final class TalkSchema {
	/** Static schema construction only. */
	private function __construct() {
	}

	/**
	 * @param string $name existing tool name
	 * @param string $operation existing grant operation
	 * @param string $description unchanged English description
	 * @param array<string,mixed>|stdClass $properties declared properties; stdClass preserves an empty JSON object
	 * @param list<string> $required required fields in their existing order; an empty list omits the key
	 * @return ToolDefinition the existing Talk/spreed definition without a native service lookup
	 */
	public static function definition(string $name, string $operation, string $description, array|stdClass $properties, array $required = []): ToolDefinition {
		$schema = ['type' => 'object', 'properties' => $properties];
		if ($required !== []) {
			$schema['required'] = $required;
		}
		$schema['additionalProperties'] = false;
		return new ToolDefinition($name, 'talk', $operation, $description, $schema, app: 'spreed');
	}

	/** @return array{type:string,minLength:int,maxLength:int} public room token bounds */
	public static function token(): array {
		return ['type' => 'string', 'minLength' => 1, 'maxLength' => 30];
	}

	/** @return array{type:string,minLength:int,maxLength:int} existing text and caption bounds */
	public static function message(): array {
		return ['type' => 'string', 'minLength' => 1, 'maxLength' => ConversationWriter::MAX_MESSAGE_LENGTH];
	}

	/** @return array{type:string,minimum:int} positive comment or attachment share identifier */
	public static function identifier(): array {
		return ['type' => 'integer', 'minimum' => 1];
	}

	/** @return array{type:string,minLength:int,maxLength:int} existing target account bounds */
	public static function user(): array {
		return ['type' => 'string', 'minLength' => 1, 'maxLength' => UserConversationResolver::MAX_TARGET_LENGTH];
	}

	/** @return array<string,mixed> existing structured reference, with cross-field validation left to the work class */
	public static function reference(): array {
		return [
			'type' => 'object',
			'properties' => [
				'type' => ['type' => 'string', 'enum' => [ReferenceLinker::TYPE_DECK_CARD, ReferenceLinker::TYPE_CALENDAR_EVENT]],
				'card_id' => self::identifier(),
				'board_id' => self::identifier(),
				'calendar' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ReferenceLinker::MAX_CALENDAR_LENGTH],
				'uid' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ReferenceLinker::MAX_UID_LENGTH],
			],
			'required' => ['type'],
			'additionalProperties' => false,
		];
	}
}
