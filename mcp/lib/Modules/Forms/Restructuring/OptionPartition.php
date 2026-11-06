<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

use OCA\Mcp\Modules\Forms\Editing\EditValues;

/**
 * Which option partition a question type has, and which options belong to it.
 *
 * Choice questions (dropdown, multiple, multiple_unique, ranking) have one partition: all their options, legacy untyped ones
 * included. Grid questions have the row and the column partitions. Other types, linearscale included, have no options.
 */
final class OptionPartition {
	/** Option types a client can name. */
	public const TYPES = ['choice', 'row', 'column'];
	/** Question types whose answers are chosen among options. */
	private const CHOICE = ['dropdown', 'multiple', 'multiple_unique', 'ranking'];

	private function __construct() {
	}

	/**
	 * @param string $questionType native question type
	 * @return string|null 'choice', 'grid' or null when the type has no options
	 */
	public static function kind(string $questionType): ?string {
		return in_array($questionType, self::CHOICE, true) ? 'choice' : ($questionType === 'grid' ? 'grid' : null);
	}

	/**
	 * @param array<string,mixed> $question projected question
	 * @param mixed $optionType requested option type (null when omitted)
	 * @param bool $required whether a choice question must name 'choice' too
	 * @return string|null native option type to send: the grid partition, 'choice' when required, otherwise null (no filter)
	 */
	public static function nativeType(array $question, mixed $optionType, bool $required): ?string {
		$kind = self::kind($question['type']);
		$valid = match ($kind) {
			'choice' => $optionType === 'choice' || (!$required && $optionType === null),
			'grid' => $optionType === 'row' || $optionType === 'column',
			default => false,
		};
		if (!$valid) {
			EditValues::invalid('option_type');
		}
		return $kind === 'grid' || $required ? $optionType : null;
	}

	/**
	 * @param array<string,mixed> $question projected question
	 * @param string|null $nativeType partition filter, null for all options
	 * @return list<array<string,mixed>> the partition, in native order
	 */
	public static function members(array $question, ?string $nativeType): array {
		return array_values(array_filter($question['options'], static fn (array $o): bool => $nativeType === null || $o['optionType'] === $nativeType));
	}
}
