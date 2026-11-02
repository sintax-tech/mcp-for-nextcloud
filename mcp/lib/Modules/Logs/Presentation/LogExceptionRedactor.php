<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Presentation;

/**
 * Summarizes an exception from a log entry: class, message, code, place and ten frames, never the arguments.
 *
 * Messages go through {@see LogTextRedactor}. Callers keep using {@see LogRedactor}, which delegates.
 */
final class LogExceptionRedactor {
	/** Frames of a stack trace kept. */
	public const TRACE_FRAMES = 10;
	/** Previous exceptions kept below the one logged: three levels in all. */
	public const PREVIOUS_LEVELS = 2;
	/** Longest exception message, as the longest log message. */
	public const MESSAGE_LIMIT = 2000;
	/** Shown in place of a hidden message or of the user's part of a URL. */
	public const HIDDEN = '[hidden]';

	private function __construct() {
	}

	/**
	 * @param mixed $exception the `exception` field of an entry, as json_decode() gave it
	 * @param bool $hideMessages whether the message of the exception and of the previous ones is hidden whole
	 * @return array<string, mixed>|null class, message, code, place, the first frames and the previous exceptions; null
	 *     when the value is not an exception
	 */
	public static function exception(mixed $exception, bool $hideMessages = false): ?array {
		return self::summary(is_object($exception) || is_array($exception) ? json_decode(json_encode($exception), true) : null, self::PREVIOUS_LEVELS, $hideMessages);
	}

	/**
	 * @param mixed $exception the exception as an array
	 * @param int $levels previous exceptions still allowed below this one
	 * @param bool $hideMessages whether the message is hidden whole
	 * @return array<string, mixed>|null the summary
	 */
	private static function summary(mixed $exception, int $levels, bool $hideMessages): ?array {
		if (!is_array($exception) || !is_string($exception['Exception'] ?? null) || $exception['Exception'] === '') {
			return null;
		}
		$trace = is_array($exception['Trace'] ?? null) ? array_values(array_filter($exception['Trace'], 'is_array')) : [];
		$summary = [
			'class' => LogTextRedactor::text($exception['Exception'], 200),
			'message' => $hideMessages ? self::HIDDEN : LogTextRedactor::text(is_scalar($exception['Message'] ?? null) ? (string)$exception['Message'] : '', self::MESSAGE_LIMIT),
			'code' => is_int($exception['Code'] ?? null) ? $exception['Code'] : 0,
			'at' => self::place($exception['File'] ?? null, $exception['Line'] ?? null),
			'trace' => array_map(self::frame(...), array_slice($trace, 0, self::TRACE_FRAMES)),
			'traceFrames' => count($trace),
		];
		$previous = $levels > 0 ? self::summary($exception['Previous'] ?? null, $levels - 1, $hideMessages) : null;
		if ($previous !== null) {
			$summary['previous'] = $previous;
		}
		return $summary;
	}

	/**
	 * @param array<string, mixed> $frame one frame of the trace
	 * @return array{call:string, at:string} class->function and file:line; the arguments are left behind
	 */
	private static function frame(array $frame): array {
		$function = is_string($frame['function'] ?? null) ? $frame['function'] : '';
		$class = is_string($frame['class'] ?? null) ? $frame['class'] . (is_string($frame['type'] ?? null) ? $frame['type'] : '->') : '';
		return ['call' => LogTextRedactor::text($class . $function, 300), 'at' => self::place($frame['file'] ?? null, $frame['line'] ?? null)];
	}

	/** @return string file:line, or '' without a file */
	private static function place(mixed $file, mixed $line): string {
		if (!is_string($file) || $file === '') {
			return '';
		}
		return LogTextRedactor::text($file, 300) . (is_int($line) ? ':' . $line : '');
	}
}
