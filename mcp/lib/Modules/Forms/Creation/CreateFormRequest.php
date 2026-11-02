<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Creation;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\L10n\Translator;

/** Validates the whole request before any native sink, preserving text bytes and order. */
final class CreateFormRequest {
	/** Normalizes optional defaults while refusing unsupported keys, settings, types and budgets.
	 * @param array<string,mixed> $args Schema-validated or direct-call arguments.
	 * @return array<string,mixed> Closed normalized request used by plan and writer.
	 * @throws ArgumentValidationException For a fixed field/rule without submitted text.
	 */
	public static function of(array $args): array {
		self::keys($args, ['title', 'description', 'questions', 'confirm', 'plan_state'], 'arguments');
		$request = ['title' => self::text($args['title'] ?? null, 1, 256, 'title'), 'description' => self::text($args['description'] ?? '', 0, 8192, 'description'), 'questions' => []];
		$questions = $args['questions'] ?? [];
		self::items($questions, 50, 'questions');
		$options = 0;
		foreach ($questions as $index => $q) {
			$at = 'questions.' . $index;
			if (!is_array($q)) { self::invalid($at); }
			self::keys($q, ['type', 'text', 'description', 'required', 'validation', 'options', 'subtype', 'rows', 'columns', 'scale'], $at);
			$type = $q['type'] ?? null;
			if (!in_array($type, CreateFormTypes::SUPPORTED, true)) { self::invalid($at . '.type'); }
			$required = $q['required'] ?? false;
			if (!is_bool($required)) { self::invalid($at . '.required'); }
			$normal = ['type' => $type, 'text' => self::text($q['text'] ?? null, 1, 2048, $at . '.text'),
				'description' => self::text($q['description'] ?? '', 0, 4096, $at . '.description'), 'required' => $required, 'settings' => [], 'options' => []];
			foreach (['validation' => ['short'], 'options' => CreateFormTypes::CHOICES, 'scale' => ['linearscale'], 'subtype' => ['grid'], 'rows' => ['grid'], 'columns' => ['grid']] as $key => $types) {
				if (array_key_exists($key, $q) && !in_array($type, $types, true)) { self::invalid($at . '.' . $key); }
			}
			if (array_key_exists('validation', $q)) {
				if (!in_array($q['validation'], ['email', 'phone', 'number'], true)) { self::invalid($at . '.validation'); }
				$normal['settings']['validationType'] = $q['validation'];
			}
			if ($type === 'grid') {
				if (!in_array($q['subtype'] ?? null, ['checkbox', 'radio', 'number'], true)) { self::invalid($at . '.subtype'); }
				$normal['settings']['questionType'] = $q['subtype'];
			}
			foreach (($type === 'grid' ? ['rows' => 'row', 'columns' => 'column'] : (in_array($type, CreateFormTypes::CHOICES, true) ? ['options' => 'choice'] : [])) as $key => $category) {
				$values = $q[$key] ?? []; self::items($values, 200, $at . '.' . $key);
				foreach ($values as $i => $value) { $values[$i] = self::text($value, 1, 1024, $at . '.' . $key . '.' . $i); }
				$normal['options'][$category] = $values; $options += count($values);
			}
			if (array_key_exists('scale', $q)) {
				$scale = $q['scale']; if (!is_array($scale)) { self::invalid($at . '.scale'); }
				self::keys($scale, ['lowest', 'highest', 'labelLowest', 'labelHighest'], $at . '.scale');
				foreach (['lowest' => [0, 1], 'highest' => [2, 10]] as $key => [$min, $max]) {
					if (array_key_exists($key, $scale)) {
						if (!is_int($scale[$key]) || $scale[$key] < $min || $scale[$key] > $max) { self::invalid($at . '.scale.' . $key); }
						$normal['settings']['options' . ucfirst($key)] = $scale[$key];
					}
				}
				foreach (['labelLowest', 'labelHighest'] as $key) {
					if (array_key_exists($key, $scale)) { $normal['settings']['options' . ucfirst($key)] = self::text($scale[$key], 0, 1024, $at . '.scale.' . $key); }
				}
			}
			$request['questions'][] = $normal;
		}
		if ($options > 1000 || self::characters($request) > 100000 || strlen(json_encode($request, JSON_THROW_ON_ERROR)) > 1048576) { self::invalid('arguments', 'exceeds the complete creation response budget'); }
		return $request;
	}
	/** Counts all textual values for the composed budget without silently cutting them.
	 * @param array<mixed> $value Normalized nested request.
	 * @return int Total character count.
	 */
	private static function characters(array $value): int {
		$count = 0;
		foreach ($value as $item) { $count += is_array($item) ? self::characters($item) : (is_string($item) ? mb_strlen($item, 'UTF-8') : 0); }
		return $count;
	}
	/** Refuses unknown keys without reflecting their potentially hostile spelling.
	 * @param array<mixed> $value Submitted object.
	 * @param list<string> $keys Allowed keys.
	 * @param string $field Declared path.
	 * @return void
	 */
	private static function keys(array $value, array $keys, string $field): void { if (array_diff(array_keys($value), $keys) !== []) { self::invalid($field); } }
	/** Checks a bounded, ordered list.
	 * @param mixed $value Submitted list.
	 * @param int $max Maximum count.
	 * @param string $field Declared path.
	 * @return void
	 */
	private static function items(mixed $value, int $max, string $field): void { if (!is_array($value) || !array_is_list($value) || count($value) > $max) { self::invalid($field); } }
	/** Preserves valid UTF-8 text exactly within native field limits.
	 * @param mixed $value Submitted text.
	 * @param int $min Minimum length.
	 * @param int $max Maximum length.
	 * @param string $field Declared path.
	 * @return string Original text.
	 */
	private static function text(mixed $value, int $min, int $max, string $field): string {
		if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') < $min || mb_strlen($value, 'UTF-8') > $max) { self::invalid($field); }
		return $value;
	}
	/** Throws a client-safe fixed validation rule.
	 * @param string $field Declared path.
	 * @param string $rule Fixed rule.
	 * @return never
	 */
	private static function invalid(string $field, string $rule = 'must match the supported creation schema'): never {
		$message = $rule === 'exceeds the complete creation response budget'
			? Translator::t('exceeds the complete creation response budget') : Translator::t('must match the supported creation schema');
		throw new ArgumentValidationException('Invalid creation request', $field, $message);
	}
}
