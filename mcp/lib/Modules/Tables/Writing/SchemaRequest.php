<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Tables\Presentation\SchemaToolSchema;

/** Validates the closed request even when a handler is invoked outside the registry. */
final class SchemaRequest {
	/**
	 * @param string $action Tool action.
	 * @param array<string,mixed> $args Raw arguments.
	 * @return array<string,mixed> Validated intent.
	 */
	public static function of(string $action, array $args): array {
		$schema = SchemaToolSchema::definition($action)->inputSchema;
		$schema['properties']['confirm'] = ['type' => 'boolean'];
		$args = ArgumentValidator::validate($schema, $args);
		$changes = $args['patch'] ?? $args;
		if (isset($args['patch']) && $args['patch'] === []) self::invalid('patch');
		if (isset($changes['title']) && trim($changes['title']) === '') self::invalid('title');
		if (isset($args['view_ids'])) self::ids($args['view_ids'], 'view_ids');
		return $args;
	}

	/**
	 * @param mixed $items Identifiers.
	 * @param string $path Fixed error path.
	 * @return list<int> Unique positive identifiers.
	 */
	public static function ids(mixed $items, string $path): array {
		if (!is_array($items) || !array_is_list($items) || count($items) > 100) self::invalid($path);
		$seen = [];
		foreach ($items as $id) {
			if (!is_int($id) || $id < 1 || isset($seen[$id])) self::invalid($path);
			$seen[$id] = true;
		}
		return $items;
	}

	/**
	 * @param string $path A fixed, non-secret field path.
	 * @return never
	 */
	public static function invalid(string $path): never {
		throw new ArgumentValidationException('Invalid argument: ' . $path, $path, SchemaMessages::invalidRule());
	}
}
