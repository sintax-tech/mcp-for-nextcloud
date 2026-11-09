<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Logging;

use Throwable;

/** Safe exception identity shared by layers that cannot depend on the tool registry. */
final class ExceptionDetails {
	/**
	 * Never pass an exception to the logger: its message and trace can name the user's files.
	 *
	 * @param Throwable $failure Failure whose class and integer code are safe to record.
	 * @return string Class and code only, without message, trace or previous exception payload.
	 */
	public static function describe(Throwable $failure): string {
		return $failure::class . ' (code ' . self::code($failure) . ')';
	}

	/**
	 * Exception subclasses can expose non-integer codes; do not send arbitrary strings to the log.
	 *
	 * @param Throwable $failure Failure being identified.
	 * @return int Integer code, or zero when the native code is not an integer.
	 */
	public static function code(Throwable $failure): int {
		$code = $failure->getCode();
		return is_int($code) ? $code : 0;
	}
}
