<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** Explicit DTO construction retains empty lists, unlike fromInputArray(). */
final class SchemaViewInput {
	/**
	 * @param array<string,mixed> $patch Validated native-key changes.
	 * @param string $profile Adapter profile.
	 * @return object|array Native update input.
	 */
	public static function of(array $patch, string $profile): object|array {
		if ($profile === '1.0.0') {
			foreach (['columnSettings', 'filter', 'sort'] as $key) if (isset($patch[$key])) $patch[$key] = json_encode($patch[$key], JSON_THROW_ON_ERROR);
			return $patch;
		}
		$title = 'OCA\\Tables\\Service\\ValueObject\\Title';
		$emoji = 'OCA\\Tables\\Service\\ValueObject\\Emoji';
		$args = [];
		foreach (['title' => $title, 'emoji' => $emoji] as $key => $class) {
			if (array_key_exists($key, $patch)) { $args[$key] = new $class($patch[$key]); }
		}
		if (array_key_exists('description', $patch)) $args['description'] = $patch['description'];
		foreach (['columnSettings' => ['OCA\\Tables\\Model\\ColumnSettings', 'columnSettings'], 'filter' => ['OCA\\Tables\\Model\\FilterSet', 'filterSet'], 'sort' => ['OCA\\Tables\\Model\\SortRuleSet', 'sortRuleSet']] as $key => [$class, $arg]) {
			if (array_key_exists($key, $patch)) { $args[$arg] = $class::createFromInputArray($patch[$key]); }
		}
		$class = 'OCA\\Tables\\Model\\ViewUpdateInput';
		return new $class(...$args);
	}
}
