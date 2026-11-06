<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

/** Pure native value normalization and version-gated UTC wrapper construction. */
final class PollsOptionValues {
	/**
	 * @param int $stamp UTC seconds.
	 * @return object Native 9.x date wrapper.
	 * @throws \Exception If the timestamp cannot be represented.
	 */
	public static function date(int $stamp): object {
		return new \OCA\Polls\Model\DateTimeImmutable('@' . $stamp);
	}
	/**
	 * @param int $seconds Nonnegative duration.
	 * @return object Native 9.x interval wrapper.
	 * @throws \Exception If the duration cannot be represented.
	 */
	public static function interval(int $seconds): object {
		return new \OCA\Polls\Model\DateInterval('PT' . $seconds . 'S');
	}
	/**
	 * @param object $value Disposable native option.
	 * @param array<string,mixed> $option Validated scalar value.
	 * @param bool $nine Version-selected native setter layout.
	 * @param string $type Native poll type.
	 * @return void
	 * @throws \Throwable If a native normalizer rejects the value.
	 */
	public static function update(object $value, array $option, bool $nine, string $type): void {
		if (!$nine) {
			$value->setOption($option['timestamp'] ?? 0, $option['duration'] ?? 0, $option['text'] ?? '');
		} elseif ($type === 'textPoll') {
			$value->setText($option['text']);
		} else {
			$value->setDateTime(self::date($option['timestamp']));
			$value->setInterval(self::interval($option['duration'] ?? 0));
		}
	}
}
