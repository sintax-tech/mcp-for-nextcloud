<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Presentation\ColumnOptions;

/** Typed column domains, checked before DTO construction on every pin. */
final class SchemaColumnChanges {
	/**
	 * @param array<string,mixed> $changes Request fields.
	 * @param array<string,mixed>|null $current Current native column.
	 * @param bool $legacyUnlimitedText Whether the proven native validator accepts -1 as unlimited.
	 * @return array<string,mixed> Native option fields merged with the snapshot.
	 */
	public static function of(array $changes, ?array $current, bool $legacyUnlimitedText = false): array {
		$type = $current['type'] ?? $changes['type']; $subtype = $current['subtype'] ?? $changes['subtype'];
		$subtypes = ['text' => ['', 'line', 'long', 'rich', 'link'], 'number' => ['', 'stars', 'progress'], 'selection' => ['', 'multi', 'check'], 'datetime' => ['', 'date', 'time'], 'usergroup' => ['']];
		if (!in_array($subtype, $subtypes[$type] ?? [], true)) SchemaRequest::invalid('type');
		$options = $changes['options'] ?? []; unset($changes['options']);
		if (!is_array($options) || !array_is_list($options) || count($options) > 20) SchemaRequest::invalid('options');
		$seen = [];
		foreach ($options as $option) {
			if (!is_array($option) || array_diff(array_keys($option), ['name', 'value']) || count($option) !== 2
				|| !is_string($option['name'] ?? null) || !array_key_exists('value', $option)
				|| !in_array($option['name'], ColumnOptions::TYPE_FIELDS[$type], true) || isset($seen[$option['name']])) SchemaRequest::invalid('options');
			$name = $option['name']; $value = $option['value']; $seen[$name] = true;
			self::value($name, $value, $subtype, $legacyUnlimitedText);
			$changes[$name] = $value;
		}
		$merged = array_replace($current ?? [], $changes);
		// Preserved native values are not client input: do not narrow their existing domain.
		$changed = array_keys($changes);
		if (($current === null || array_intersect($changed, ['numberMin', 'numberMax'])) && isset($merged['numberMin'], $merged['numberMax']) && $merged['numberMin'] > $merged['numberMax']) SchemaRequest::invalid('options');
		if (($current === null || array_intersect($changed, ['numberDefault', 'numberMin', 'numberMax'])) && isset($merged['numberDefault']) && (($merged['numberMin'] ?? -INF) > $merged['numberDefault'] || ($merged['numberMax'] ?? INF) < $merged['numberDefault'])) SchemaRequest::invalid('options');
		if (($current === null || array_intersect($changed, ['numberDefault'])) && $type === 'number' && in_array($subtype, ['stars', 'progress'], true) && isset($merged['numberDefault'])
			&& (!is_numeric($merged['numberDefault']) || floor($merged['numberDefault']) !== (float)$merged['numberDefault']
				|| $merged['numberDefault'] < 0 || $merged['numberDefault'] > ($subtype === 'stars' ? 5 : 100))) SchemaRequest::invalid('options');
		if ($type === 'selection' && ($current === null || array_intersect($changed, ['selectionOptions', 'selectionDefault']))) self::selection($merged, $current, $subtype);
		return $merged;
	}
	/**
	 * @param string $name Whitelisted option.
	 * @param mixed $value Typed value.
	 * @param string $subtype Column subtype.
	 * @param bool $legacyUnlimitedText Native unlimited text convention.
	 * @return void
	 */
	private static function value(string $name, mixed $value, string $subtype, bool $legacyUnlimitedText): void {
		if (in_array($name, ['selectionOptions', 'selectionDefault', 'usergroupDefault'], true)) return;
		if (in_array($name, ['numberDefault', 'numberMin', 'numberMax'], true)) {
			if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) SchemaRequest::invalid('options');
		} elseif (in_array($name, ['textMaxLength', 'numberDecimals'], true)) {
			if (!is_int($value) || $value < ($name === 'textMaxLength' && $legacyUnlimitedText ? -1 : 0) || $value > 100000) SchemaRequest::invalid('options');
		} elseif (in_array($name, ['textUnique', 'usergroupMultipleItems', 'usergroupSelectUsers', 'usergroupSelectGroups', 'usergroupSelectTeams', 'showUserStatus'], true)) {
			if (!is_bool($value)) SchemaRequest::invalid('options');
		} else {
			if (!is_string($value) || strlen($value) > 10000) SchemaRequest::invalid('options');
			if ($name === 'textAllowedPattern') {
				if (strlen($value) > 512) SchemaRequest::invalid('options');
				// Native text regex semantics are unproved; only the proven link-provider CSV may be written.
				if ($subtype !== 'link' || preg_match('/^(?:url|files|contacts)(?:,(?:url|files|contacts))*$/D', $value) !== 1) SchemaRequest::invalid('options');
			}
			if ($name === 'textDefault' && $subtype === 'link' && $value !== '' && !CellValueDomain::isCanonicalLink(['type' => 'text', 'subtype' => 'link'], $value)) SchemaRequest::invalid('options');
			if ($name === 'datetimeDefault' && $value !== '') {
				$format = match ($subtype) { 'date' => 'Y-m-d', 'time' => 'H:i', default => 'Y-m-d H:i' };
				$d = \DateTimeImmutable::createFromFormat('!' . $format, $value);
				if ($d === false || $d->format($format) !== $value) SchemaRequest::invalid('options');
			}
		}
	}
	/**
	 * @param array<string,mixed> $data Merged column.
	 * @param array<string,mixed>|null $old Previous snapshot.
	 * @param string $subtype Selection subtype.
	 * @return void
	 */
	private static function selection(array &$data, ?array $old, string $subtype): void {
		$options = $data['selectionOptions'] ?? [];
		if (!is_array($options) || !array_is_list($options) || count($options) > 100) SchemaRequest::invalid('options');
		$ids = [];
		foreach ($options as $option) {
			if (!is_array($option) || array_diff(array_keys($option), ['id', 'label', 'uuid']) || !is_int($option['id'] ?? null)
				|| $option['id'] < 1 || isset($ids[$option['id']]) || !is_string($option['label'] ?? null) || mb_strlen($option['label']) > 200) SchemaRequest::invalid('options');
			$ids[$option['id']] = true;
		}
		if ($old !== null) {
			$prior = $old['selectionOptions'] ?? [];
			foreach ($prior as $index => $option) {
				if (($options[$index]['id'] ?? null) !== $option['id']) SchemaRequest::invalid('options');
				if (isset($option['uuid'])) $options[$index]['uuid'] = $option['uuid'];
			}
		}
		$data['selectionOptions'] = $options;
		$default = $data['selectionDefault'] ?? null;
		if ($subtype === 'check') {
			if ($options !== [] || ($default !== null && !is_bool($default))) SchemaRequest::invalid('options');
		} elseif ($subtype === 'multi') {
			if ($default !== null) foreach (SchemaRequest::ids($default, 'options') as $id) if (!isset($ids[$id])) SchemaRequest::invalid('options');
		} elseif ($default !== null && (!is_int($default) || !isset($ids[$default]))) SchemaRequest::invalid('options');
	}
}
