<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Writing;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;

/** Validates direct calls as strictly as registry calls, without changing submitted text. */
final class PollWriteRequest {
	/**
	 * @param string $tool Exact name.
	 * @param array<string,mixed> $args Raw arguments.
	 * @return array<string,mixed> Ordered immutable effect arguments.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If a field or requested transition is invalid.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If a target or effective sharing policy refuses the operation.
	 */
	public static function of(string $tool, array $args): array {
		$schema = PollsWriteSchema::schema($tool);
		$schema['properties'] += ['confirm' => ['type' => 'boolean']];
		$valid = ArgumentValidator::validate($schema, $args);
		unset($valid['confirm'], $valid['plan_state']);
		self::strings($valid);
		if (strlen(json_encode($valid, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 65536) {
			self::invalid('arguments');
		}
		if (isset($valid['changes'])) {
			if ($valid['changes'] === []) {
				self::invalid('changes');
			}
			if (isset($valid['changes']['title']) && (trim($valid['changes']['title']) === '' || $valid['changes']['title'] === '0')) {
				self::invalid('changes.title');
			}
			if (isset($valid['changes']['timezoneName']) && !in_array(
				$valid['changes']['timezoneName'],
				\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),
				true,
			)) {
				self::invalid('changes.timezoneName');
			}
			ksort($valid['changes']);
		}
		if (isset($valid['option'])) {
			$o = $valid['option'];
			if (isset($o['text'])) {
				if (count($o) !== 1 || trim($o['text']) === '' || $o['text'] === '0' || strlen($o['text']) > 4096) {
					self::invalid('option');
				}
			} else {
				if (!isset($o['timestamp'])) {
					self::invalid('option');
				}
				$valid['option']['duration'] ??= 0;
				if ($o['timestamp'] + $valid['option']['duration'] > 253402300799) {
					self::invalid('option');
				}
			}
		}
		if (isset($valid['option_ids']) && count(array_unique($valid['option_ids'])) !== count($valid['option_ids'])) {
			self::invalid('option_ids');
		}
		if ($tool === 'polls_add_share') {
			self::recipient($valid);
		}
		if ($tool === 'polls_update_share' && ($valid['action'] === 'label') !== array_key_exists('value', $valid)) {
			self::invalid('value');
		}
		return ['tool' => $tool] + $valid;
	}

	/**
	 * @param array<string,mixed> $args Closed recipient input.
	 * @return void
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If a field or requested transition is invalid.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If a target or effective sharing policy refuses the operation.
	 */
	private static function recipient(array $args): void {
		$type = $args['type'];
		if ($type === 'public') {
			if (array_key_exists('recipient', $args)) {
				self::invalid('recipient');
			}
			return;
		}
		$key = match ($type) {
			'user', 'admin' => 'user_id',
			'group' => 'group_id',
			'email' => 'email_address',
			'contact' => 'contact_id',
		};
		$r = $args['recipient'] ?? [];
		$allowed = $type === 'email' ? [$key, 'display_name'] : [$key];
		if (!isset($r[$key]) || trim($r[$key]) === '' || array_diff(array_keys($r), $allowed) !== []) {
			self::invalid('recipient');
		}
		if ($type === 'email' && filter_var($r[$key], FILTER_VALIDATE_EMAIL) === false) {
			self::invalid('recipient.email_address');
		}
	}

	/**
	 * @param array<string,mixed> $value Nested user input.
	 * @return void
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If a field or requested transition is invalid.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If a target or effective sharing policy refuses the operation.
	 */
	private static function strings(array $value): void {
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				self::strings($item);
			} elseif (is_string($item)
				&& (!mb_check_encoding($item,
				'UTF-8')
				|| preg_match('/[\x{061c}\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u',
				$item)
				|| preg_match($key === 'description'
				? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u'
				: '/[\p{Cc}]/u',
				$item))) {
				self::invalid('arguments');
			}
		}
	}

	/**
	 * @param string $field Fixed schema field.
	 * @return never
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If a field or requested transition is invalid.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If a target or effective sharing policy refuses the operation.
	 */
	public static function invalid(string $field): never {
		throw new ArgumentValidationException(
			'Invalid argument: ' . $field,
			$field,
			\OCA\Mcp\L10n\Translator::t('Use the declared Polls fields and limits.')
		);
	}
}
