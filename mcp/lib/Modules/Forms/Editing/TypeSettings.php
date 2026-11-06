<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Forms\Restructuring\QuestionTypes;

/**
 * Our validation of the final settings of a type change (decision F5): the native updateQuestion never validates the type.
 *
 * It reuses the closed key/value rules of point editing (EditSettings) for the target type, without changing them, so the
 * forms_update_question schema stays as it is.
 */
final class TypeSettings {
	private function __construct() {
	}

	/**
	 * @return array<string,array<string,mixed>> JSON schema of every settings key of every target type
	 */
	public static function schema(): array {
		$properties = [];
		foreach (QuestionTypes::TARGETS as $type) {
			foreach (EditSettings::keys($type) as $key) {
				$properties[$key] = EditSettingSchema::value($key);
			}
		}
		ksort($properties);
		return $properties;
	}

	/**
	 * @param string $type target type
	 * @param mixed $settings requested final settings
	 * @return array<string,mixed> canonical final settings, valid for the target type
	 */
	public static function final(string $type, mixed $settings): array {
		if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
			EditValues::invalid('settings');
		}
		try {
			return EditSettings::merge($type, [], ['settings' => $settings]);
		} catch (ArgumentValidationException) {
			EditValues::invalid('settings');
		}
	}

	/**
	 * @param string $type target type
	 * @param array<string,mixed> $current stored settings of the question
	 * @return list<string> keys that the target type does not accept (empty when the current settings are valid as they are)
	 */
	public static function incompatible(string $type, array $current): array {
		try {
			EditSettings::merge($type, [], ['settings' => $current]);
			return [];
		} catch (ArgumentValidationException) {
			$keys = array_values(array_diff(array_keys($current), EditSettings::keys($type)));
			return $keys === [] ? array_keys($current) : $keys;
		}
	}
}
