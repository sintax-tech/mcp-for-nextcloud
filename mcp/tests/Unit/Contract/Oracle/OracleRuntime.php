<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use PHPUnit\Framework\TestCase;

/** Identifies the runtime inputs frozen by the environment-sensitive RE-01 transcripts. */
final class OracleRuntime {
	/** These plans were frozen without the optional Intl date formatter. */
	private const WITHOUT_INTL = [
		'calendar.create-event.json', 'calendar.move-event.json', 'calendar.delete-event.json',
		'calendar.transfer-event.json', 'tasks.create-task.json', 'tasks.complete-task.json',
	];
	/** These transcripts include the PHP request-body limit, frozen at eight MiB. */
	private const POST_LIMIT = ['settings.checkout-limit.json', 'files.upload.json'];

	/** Static runtime checks only. */
	private function __construct() {
	}

	/**
	 * Refuses incompatible inputs before any golden diff or regeneration, without hiding a regression.
	 *
	 * @param TestCase $test Existing contract test.
	 * @param string $path Golden transcript being checked.
	 * @return void
	 */
	public static function assertCanonical(TestCase $test, string $path): void {
		$name = basename($path);
		$reasons = [];
		if (in_array($name, self::WITHOUT_INTL, true) && class_exists(\IntlDateFormatter::class)) {
			$reasons[] = 'ext-intl is present; this transcript was frozen without IntlDateFormatter';
		}
		if (in_array($name, self::POST_LIMIT, true) && ini_parse_quantity((string)ini_get('post_max_size')) !== 8 * 1024 * 1024) {
			$reasons[] = 'post_max_size=' . ini_get('post_max_size') . '; this transcript requires 8M';
		}
		if ($reasons !== []) { $test->fail('non-canonical runtime: ' . implode('; ', $reasons)); }
	}
}
