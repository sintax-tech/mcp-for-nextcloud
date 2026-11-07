<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Reading\RowsRefusal;
use OCA\Mcp\Modules\Tables\Reading\RowFilterDomain;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Closed native view patches with explicit preservation of existing relation references. */
final class SchemaViewChanges {
	/**
	 * @param array<string,mixed> $patch Client patch.
	 * @param array<string,mixed> $before Native view.
	 * @param array<int,array<string,mixed>> $columns Columns of its table.
	 * @param string $profile Native API.
	 * @return array<string,mixed> Native-key patch.
	 */
	public static function of(array $patch, array $before, array $columns, string $profile): array {
		if ($profile !== '1.0.0' && $profile !== '2.3.2' && isset($patch['description']) && in_array($patch['description'], ['', '0'], true)) SchemaRequest::invalid('patch.description');
		if (isset($patch['emoji']) && in_array($patch['emoji'], ['', '0'], true) && $profile !== '1.0.0') SchemaRequest::invalid('patch.emoji');
		if (array_key_exists('columns', $patch)) {
			$settings = [];
			$oldSettings = $before['columnSettings'] ?? $before['columns'] ?? [];
			$byId = [];
			foreach ($oldSettings as $setting) {
				$setting = (array)$setting;
				if (isset($setting['columnId'])) $byId[(int)$setting['columnId']] = $setting;
			}
			foreach (SchemaRequest::ids($patch['columns'], 'patch.columns') as $order => $id) {
				self::column($id, $columns);
				$settings[] = array_replace($byId[$id] ?? ['columnId' => $id, 'readonly' => false, 'mandatory' => false], ['order' => $order]);
			}
			self::relations($settings, $oldSettings, $columns);
			unset($patch['columns']);
			$patch['columnSettings'] = $settings;
		}
		foreach (['filter', 'sort'] as $key) if (array_key_exists($key, $patch)) {
			if ($key === 'filter') {
				$existing = array_merge([], ...($before['filter'] ?? []));
				foreach ($existing as $rule) if (($columns[(int)$rule['columnId']]['type'] ?? '') === 'relation') {
					// Grouping changes would change the relation predicate even when its flattened rule is unchanged.
					SchemaRequest::invalid('patch.filter');
				}
			}
			$groups = $key === 'filter' ? $patch[$key] : [$patch[$key]];
			if (!array_is_list($groups) || count($groups) > 100) SchemaRequest::invalid('patch.' . $key);
			$count = 0;
			$all = [];
			$seen = [];
			foreach ($groups as $group) {
				if (!is_array($group) || !array_is_list($group) || ($key === 'filter' && $group === [])) SchemaRequest::invalid('patch.' . $key);
				$out = [];
				foreach ($group as $rule) {
					$fields = $key === 'sort' ? ['column_id', 'mode'] : ['column_id', 'operator', 'value'];
					if (++$count > 100 || !is_array($rule) || array_diff(array_keys($rule), $fields) || count($rule) !== count($fields)) SchemaRequest::invalid('patch.' . $key);
					$id = $rule['column_id'] ?? null;
					if (!is_int($id) || $id < 1) SchemaRequest::invalid('patch.' . $key);
					self::column($id, $columns);
					if ($key === 'sort') {
						if (isset($seen[$id]) || !in_array($rule['mode'], ['ASC', 'DESC'], true)) SchemaRequest::invalid('patch.sort');
						$seen[$id] = true;
					} else {
						if (!in_array($rule['operator'], ['begins-with', 'ends-with', 'contains', 'does-not-contain', 'is-equal', 'is-not-equal', 'is-greater-than', 'is-greater-than-or-equal', 'is-lower-than', 'is-lower-than-or-equal', 'is-empty'], true)
							|| (!is_string($rule['value']) && !is_int($rule['value']) && !is_float($rule['value']))
							|| (is_float($rule['value']) && !is_finite($rule['value'])) || (is_string($rule['value']) && strlen($rule['value']) > 10000)) SchemaRequest::invalid('patch.filter');
					}
					if ($key === 'filter') {
						try { $rule = (new RowFilterDomain($profile))->condition($id, $columns[$id], $rule['operator'], $rule['value']); }
						catch (RowsRefusal) { SchemaRequest::invalid('patch.filter'); }
					} else {
						unset($rule['column_id']);
						$rule = ['columnId' => $id] + $rule;
					}
					$out[] = $rule;
				}
				$all[] = $out;
			}
			$next = $key === 'sort' ? $all[0] : $all;
			if ($key === 'sort') {
				foreach ($before['sort'] ?? [] as $rule) if (($columns[(int)$rule['columnId']]['type'] ?? '') === 'relation' && $next !== ($before['sort'] ?? [])) SchemaRequest::invalid('patch.sort');
			}
			self::relations($key === 'filter' ? array_merge([], ...$next) : $next,
				$key === 'filter' ? array_merge([], ...($before[$key] ?? [])) : ($before[$key] ?? []), $columns);
			$patch[$key] = $next;
		}
		return $patch;
	}
	/**
	 * @param int $id Column ID.
	 * @param array<int,array<string,mixed>> $columns Authorized columns.
	 * @return void
	 */
	private static function column(int $id, array $columns): void {
		if (!isset($columns[$id])) throw new ToolFailure(CommonMessages::notFound());
	}
	/**
	 * @param array $next New references.
	 * @param array $before Existing references.
	 * @param array $columns Native columns.
	 * @return void
	 */
	private static function relations(array $next, array $before, array $columns): void {
		$relations = static fn (array $items): array => array_values(array_filter($items, static fn ($item): bool => ($columns[(int)((array)$item)['columnId']]['type'] ?? '') === 'relation'));
		if ($relations($next) !== $relations($before)) SchemaRequest::invalid('patch');
	}
}
