<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tools\Forms\Editing;

/** Owns the fixed MCP settings domain, explicit removals and native false normalization. */
final class EditSettings
{
	public const TYPES = ['short', 'long', 'color', 'date', 'time', 'datetime', 'linearscale', 'multiple', 'multiple_unique', 'dropdown', 'file', 'grid', 'ranking'];
	/** Returns only fields the editing slice supports for the existing immutable type.
	 * @param string $type Current question type.
	 * @return list<string> Closed keys.
	 */
	public static function keys(string $type): array
	{
		return match ($type) {
			'short' => ['validationType', 'validationRegex'],
			'dropdown', 'multiple_unique' => ['allowOtherAnswer', 'shuffleOptions'],
			'multiple' => ['allowOtherAnswer', 'shuffleOptions', 'optionsLimitMin', 'optionsLimitMax'],
			'date' => ['dateMin', 'dateMax', 'dateRange'],
			'time' => ['timeMin', 'timeMax', 'timeRange'],
			'linearscale' => ['optionsLowest', 'optionsHighest', 'optionsLabelLowest', 'optionsLabelHighest'],
			'file' => ['allowedFileTypes', 'allowedFileExtensions', 'maxAllowedFilesCount', 'maxFileSize'],
			'ranking' => ['shuffleOptions'],
			default => [],
		};
	}
	/** Merges disjoint explicit operations and validates the entire final admitted map.
	 * @param string $type Immutable native type.
	 * @param array<string,mixed> $old Stored settings.
	 * @param array<string,mixed> $changes Validated change object.
	 * @return array<string,mixed> Exact map to send to the native validator/controller.
	 */
	public static function merge(string $type, array $old, array $changes): array
	{
		$keys = self::keys($type);
		$set = $changes['settings'] ?? [];
		$unset = $changes['unset_settings'] ?? [];
		EditValues::object($old, $keys, 'changes.settings');
		EditValues::object($set, $keys, 'changes.settings');
		foreach ($unset as $key) {
			if (!in_array($key, $keys, true) || $type === 'file' && $key === 'maxAllowedFilesCount') {
				EditValues::invalid('changes.unset_settings');
			}
			unset($old[$key]);
		}
		$final = array_replace($old, $set);
		foreach ($final as $key => $value) {
			self::value($key, $value);
		}
		if ($type === 'multiple' && isset($final['optionsLimitMin'], $final['optionsLimitMax']) && $final['optionsLimitMin'] > $final['optionsLimitMax']) {
			EditValues::invalid('changes.settings');
		}
		foreach (['date' => ['dateMin', 'dateMax'], 'time' => ['timeMin', 'timeMax']] as $boundType => [$min, $max]) {
			if ($type === $boundType && isset($final[$min], $final[$max]) && $final[$min] > $final[$max]) {
				EditValues::invalid('changes.settings');
			}
		}
		if ($type === 'short' && ($final['validationType'] ?? null) === 'regex' && !isset($final['validationRegex'])) {
			EditValues::invalid('changes.settings');
		}
		if (strlen(EditValues::json($final)) > 8192) {
			EditValues::invalid('changes.settings');
		}
		return EditValues::canonical($final);
	}
	/** Reproduces only the entity's public, documented false-removal normalization.
	 * @param array<string,mixed> $settings Validated map.
	 * @return array<string,mixed> Expected stored map.
	 */
	public static function stored(array $settings): array
	{
		return EditValues::canonical(array_filter($settings, static fn(mixed $v): bool => $v !== false));
	}
	/** Validates one field without admitting untyped arrays, future keys or coercion.
	 * @param string $key Fixed admitted key.
	 * @param mixed $v Candidate value.
	 * @return void
	 */
	private static function value(string $key, mixed $v): void
	{
		$nullable = ['dateMin', 'dateMax', 'dateRange', 'timeMin', 'timeMax', 'timeRange', 'optionsLowest', 'optionsHighest', 'optionsLabelLowest', 'optionsLabelHighest'];
		if ($v === null && in_array($key, $nullable, true)) {
			return;
		}
		$valid = match ($key) {
			'allowOtherAnswer', 'shuffleOptions', 'dateRange', 'timeRange' => is_bool($v),
			'optionsLimitMin', 'optionsLimitMax' => is_int($v) && $v >= 0 && $v <= 200,
			'maxAllowedFilesCount' => is_int($v) && $v >= 1 && $v <= 200,
			'maxFileSize' => is_int($v) && $v >= 0,
			'dateMin', 'dateMax' => is_int($v),
			'timeMin', 'timeMax' => is_string($v) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $v) === 1,
			'optionsLowest' => is_int($v) && $v >= 0 && $v <= 1,
			'optionsHighest' => is_int($v) && $v >= 2 && $v <= 10,
			'validationType' => in_array($v, ['email', 'phone', 'number', 'regex'], true),
			'validationRegex', 'optionsLabelLowest', 'optionsLabelHighest' => is_string($v),
			'allowedFileTypes', 'allowedFileExtensions' => is_array($v) && array_is_list($v) && count($v) <= 32,
			default => false,
		};
		if (!$valid) {
			EditValues::invalid('changes.settings');
		}
		if (is_string($v)) {
			EditValues::text($v, 0, 1024, 'changes.settings');
		}
		if (is_array($v)) {
			foreach ($v as $item) {
				if ($key === 'allowedFileTypes' ? !in_array($item, ['image', 'x-office/document', 'x-office/presentation', 'x-office/spreadsheet'], true) : !is_string($item) || preg_match('/^[a-zA-Z0-9]{1,16}$/D', $item) !== 1) {
					EditValues::invalid('changes.settings');
				}
			}
		}
	}
}
