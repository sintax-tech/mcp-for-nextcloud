<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Schema;

/** Ordered tool definition envelopes, text fields and MCP result encoding shared across modules. */
final class SchemaBuilder {

	/**
	 * @param string $module Grant module.
	 * @param string $app Required native app.
	 * @param string $name tool name
	 * @param string $description English source text shown to calendar-tool clients
	 * @param string $operation grant operation
	 * @param array<string, array<string, mixed>> $properties JSON Schema properties
	 * @param list<string> $required required property names
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
	 */
	public static function definition(string $module, string $app, string $name, string $description, string $operation, array $properties, array $required = []): array {
		$schema = ['type' => 'object', 'properties' => (object)$properties, 'additionalProperties' => false];
		if ($required !== []) {
			$schema['required'] = $required;
		}
		return [
			'name' => $name,
			'description' => $description,
			'inputSchema' => $schema,
			'module' => $module,
			'operation' => $operation,
			'app' => $app,
		];
	}

	/**
	 * @param string $description English source text shown to calendar-tool clients
	 * @param int $minLength minimum length
	 * @param int $maxLength maximum length
	 * @return array<string, mixed> text property
	 */
	public static function text(string $description, int $minLength, int $maxLength): array {
		return ['type' => 'string', 'minLength' => $minLength, 'maxLength' => $maxLength, 'description' => $description];
	}

	/**
	 * @param mixed ...$blocks values encoded as indented JSON, one text block each
	 * @return array{content: list<array{type:string, text:string}>} MCP result
	 */
	public static function result(mixed ...$blocks): array {
		$content = [];
		foreach ($blocks as $block) {
			$content[] = ['type' => 'text', 'text' => json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)];
		}
		return ['content' => $content];
	}

	/**
	 * @param string $message translated user-facing error message
	 * @return array{content: list<array{type:string, text:string}>, isError: bool} MCP error result
	 */
	public static function error(string $message): array {
		return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
	}
}
