<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Editing;

/** Validates the whole point request before resolving native Forms or performing a write. */
final class EditRequest {
	/** Reads exact IDs and a closed, bounded point patch; null is never a removal instruction.
	 * @param array<string,mixed> $args Submitted arguments.
	 * @param bool $option Whether this is an option update.
	 * @return array<string,mixed> Canonical request; confirmation values excluded from the effect.
	 */
	public static function of(array $args, bool $option): array {
		EditValues::object($args, ['form_id','question_id','option_id','changes','confirm','confirm_shared','plan_state'], 'arguments');
		foreach (['confirm','confirm_shared'] as $key) { if (array_key_exists($key, $args) && !is_bool($args[$key])) { EditValues::invalid($key); } }
		if (!$option && array_key_exists('option_id', $args)) { EditValues::invalid('option_id'); }
		$request = ['option' => $option];
		foreach ($option ? ['form_id','question_id','option_id'] : ['form_id','question_id'] as $key) {
			if (!is_int($args[$key] ?? null) || $args[$key] < 1) { EditValues::invalid($key); } $request[$key] = $args[$key];
		}
		$c = EditValues::object($args['changes'] ?? null, $option ? ['text'] : ['text','description','required','settings','unset_settings'], 'changes');
		if ($c === [] || (array_diff(array_keys($c), ['settings','unset_settings']) === [] && ($c['settings'] ?? []) === [] && ($c['unset_settings'] ?? []) === [])) { EditValues::invalid('changes'); }
		foreach (['text' => ($option ? 1024 : 2048), 'description' => 4096] as $key => $max) { if (array_key_exists($key, $c)) { $c[$key] = EditValues::text($c[$key], $key === 'description' ? 0 : 1, $max, 'changes.' . $key); } }
		if (array_key_exists('required', $c) && !is_bool($c['required'])) { EditValues::invalid('changes.required'); }
		if (array_key_exists('settings', $c) && (!is_array($c['settings']) || ($c['settings'] !== [] && array_is_list($c['settings'])))) { EditValues::invalid('changes.settings'); }
		if (array_key_exists('unset_settings', $c)) {
			$unset = $c['unset_settings'];
			if (!is_array($unset) || !array_is_list($unset) || count($unset) > 32) { EditValues::invalid('changes.unset_settings'); }
			foreach ($unset as $key) { if (!is_string($key)) { EditValues::invalid('changes.unset_settings'); } }
			if (count(array_unique($unset)) !== count($unset)) { EditValues::invalid('changes.unset_settings'); }
			sort($c['unset_settings']);
		}
		if (array_intersect(array_keys($c['settings'] ?? []), $c['unset_settings'] ?? []) !== []) { EditValues::invalid('changes.unset_settings'); }
		if (strlen(EditValues::json($c)) > 65536) { EditValues::invalid('changes'); }
		$request['changes'] = EditValues::canonical($c);
		return $request;
	}
}
