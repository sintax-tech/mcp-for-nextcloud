<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls\Create;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;

/** Closed, bounded input. No repair or truncation of data that will be written. */
final class CreateRequest {
	/**
	 * Validates also direct handler calls, independently of JSON schema.
	 * @param array<string,mixed> $args tool arguments
	 * @return array{type:string,title:string,options:list<array<string,mixed>>} immutable payload
	 * @throws ArgumentValidationException for an invalid declared field
	 */
	public static function of(array $args): array {
		foreach (array_keys($args) as $key) {
			if (!in_array($key, ['type','title','options','confirm','plan_state','confirm_shared'], true)) {
				self::invalid('arguments');
			}
		}
		$type = $args['type'] ?? null;
		if (!in_array($type, ['textPoll','datePoll'], true)) { self::invalid('type'); }
		$title = self::text($args['title'] ?? null, 128, 512, 'title');
		$options = array_key_exists('options',$args) ? $args['options'] : [];
		if (!is_array($options) || !array_is_list($options) || count($options) > 100) { self::invalid('options'); }
		$rows = []; $bytes = strlen($title);
		foreach ($options as $row) {
			if (!is_array($row) || array_is_list($row)) { self::invalid('options'); }
			$allowed = $type === 'textPoll' ? ['text'] : ['timestamp','duration'];
			if (array_diff(array_keys($row), $allowed) !== []) { self::invalid('options'); }
			if ($type === 'textPoll') {
				$text = self::text($row['text'] ?? null, 1024, 4096, 'options');
				$bytes += strlen($text); $rows[] = ['text'=>$text];
			} else {
				$stamp = $row['timestamp'] ?? null; $duration = array_key_exists('duration',$row) ? $row['duration'] : 0;
				if (!is_int($stamp) || $stamp < 1 || $stamp > 253402214400 || !is_int($duration)
					|| $duration < 0 || $duration > 2678400 || $stamp + $duration > 253402300799) { self::invalid('options'); }
				$rows[] = ['timestamp'=>$stamp,'duration'=>$duration];
			}
		}
		if ($bytes > 131072) { self::invalid('options'); }
		return ['type'=>$type,'title'=>$title,'options'=>$rows];
	}

	/**
	 * Keeps valid literal text byte-identical; controls and bidi are refused before the sink.
	 * @param mixed $value submitted value
	 * @param int $chars character ceiling
	 * @param int $bytes byte ceiling
	 * @param string $field declared field
	 * @return string validated original value
	 * @throws ArgumentValidationException for invalid text
	 */
	private static function text(mixed $value, int $chars, int $bytes, string $field): string {
		if (!is_string($value) || $value === '0' || !mb_check_encoding($value, 'UTF-8') || strlen($value) > $bytes
			|| mb_strlen($value) > $chars || preg_match('/[^\s\p{Z}]/u', $value) !== 1
			|| preg_match('/[\p{Cc}\x{061c}\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $value) === 1) { self::invalid($field); }
		return $value;
	}

	/**
	 * Fixed validation metadata never includes the value or an arbitrary submitted key.
	 * @param string $field declared field
	 * @return never
	 * @throws ArgumentValidationException always
	 */
	private static function invalid(string $field): never {
		throw new ArgumentValidationException('Invalid argument: ' . $field, $field, 'use the declared Polls creation fields and limits');
	}
}
