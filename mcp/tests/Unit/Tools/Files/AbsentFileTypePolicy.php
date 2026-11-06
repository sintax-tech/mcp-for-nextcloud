<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Modules\Files\Writing\ContentTypeGate;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A real file-type guard over a store that has no policy key.
 *
 * Absence is mode off. A null guard is not used: production always injects one, and an unreadable
 * policy must refuse the write instead of behaving as if the policy were off.
 */
final class AbsentFileTypePolicy {
	/**
	 * @param TestCase $test The test whose mock builder creates the database double.
	 * @return FileTypeGuard Guard whose snapshot is the factory policy.
	 */
	public static function guard(TestCase $test): FileTypeGuard {
		return self::open($test)['guard'];
	}

	/**
	 * @param TestCase $test The test whose mock builder creates the database double.
	 * @return ContentTypeGate Gate over {@see self::guard()}.
	 */
	public static function gate(TestCase $test): ContentTypeGate {
		return self::open($test)['gate'];
	}

	/**
	 * One store, one policy and one guard, so a later write to the store is what the next snapshot reads.
	 *
	 * @param TestCase $test The test whose mock builder creates the database double.
	 * @return array{config: InMemoryAppConfig, policy: FileWritePolicy, guard: FileTypeGuard, gate: ContentTypeGate}
	 */
	public static function open(TestCase $test): array {
		$config = new InMemoryAppConfig();
		$db = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IDBConnection::class);
		$policy = new FileWritePolicy($config->worker($test), $db);
		$guard = new FileTypeGuard($policy);
		return [
			'config' => $config,
			'policy' => $policy,
			'guard' => $guard,
			'gate' => new ContentTypeGate($guard),
		];
	}
}
