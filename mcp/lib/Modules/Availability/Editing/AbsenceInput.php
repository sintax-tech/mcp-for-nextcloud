<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Editing;

use DateTimeImmutable;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Strict arguments of availability_set_absence. Days are real YYYY-MM-DD dates with the first not after the last; the
 * status keeps the native 100-character limit (OutOfOfficeController::setOutOfOffice) and the message an explicit one.
 */
final class AbsenceInput {
	/** Native limit of the short status, in characters. */
	public const STATUS_MAX = 100;
	/** Explicit limit of the message, in characters; the native column is TEXT. */
	public const MESSAGE_MAX = 4000;
	/** Stored length of a user ID and of the replacement display name (dav_absence columns). */
	public const NAME_MAX = 64;
	/**
	 * @param array<string,mixed> $arguments arguments validated against the published schema
	 * @return array{first_day:string,last_day:string,status:string,message:string,replacement:array{0:string,1:?string}} replacement is ['keep', null] or ['set', ?uid]
	 * @throws ToolFailure for an impossible date, inverted days or a text over its limit
	 */
	public function parse(array $arguments): array {
		$first = $this->day($arguments['first_day'] ?? null);
		$last = $this->day($arguments['last_day'] ?? null);
		if ($first > $last) throw new ToolFailure(Translator::t('The first day of the absence must not be after its last day.'));
		$status = (string)($arguments['status'] ?? '');
		$message = (string)($arguments['message'] ?? '');
		if (!mb_check_encoding($status, 'UTF-8') || mb_strlen($status) > self::STATUS_MAX) {
			throw new ToolFailure(Translator::t('The absence status can have at most %d characters.', [self::STATUS_MAX]));
		}
		if (!mb_check_encoding($message, 'UTF-8') || mb_strlen($message) > self::MESSAGE_MAX) {
			throw new ToolFailure(Translator::t('The absence message can have at most %d characters.', [self::MESSAGE_MAX]));
		}
		$replacement = ['keep', null];
		if (array_key_exists('replacement_user_id', $arguments)) {
			$id = $arguments['replacement_user_id'];
			if ($id !== null && (!is_string($id) || $id === '' || strlen($id) > self::NAME_MAX)) throw new ToolFailure(Translator::t('The replacement user was not found.'));
			$replacement = ['set', $id];
		}
		return ['first_day' => $first, 'last_day' => $last, 'status' => $status, 'message' => $message, 'replacement' => $replacement];
	}
	/**
	 * @param mixed $value argument
	 * @return string the same day, when it is a real calendar date
	 * @throws ToolFailure otherwise
	 */
	private function day(mixed $value): string {
		$day = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
		if ($day === false || $day->format('Y-m-d') !== $value) throw new ToolFailure(Translator::t('Use real calendar days in the form YYYY-MM-DD.'));
		return $value;
	}
}
