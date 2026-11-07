<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** Per-test policy wiring, keeping logic out of the legacy Files test base. */
final class CopyMovePolicyFixture {
	/** @var \WeakMap<TestCase, self>|null Per-test fixtures released with their tests. */
	private static ?\WeakMap $fixtures = null;
	/** @var array<string, mixed>|null Null means the fresh-install absent key. */
	public ?array $value = null;
	/** @var list<array<string, mixed>> Optional consecutive policy values for boundary races. */
	public array $reads = [];
	/** Number of policy reads, including sink revalidation. */
	public int $count = 0;

	/** @param FileTypeGuard $guard The real evaluator over mocked configuration only. */
	private function __construct(public readonly FileTypeGuard $guard) {
	}

	/**
	 * @param TestCase $test Owner of fixture and PHPUnit mocks.
	 * @return self Mutable policy fixture for this test alone.
	 */
	public static function forTest(TestCase $test): self {
		self::$fixtures ??= new \WeakMap();
		if (!isset(self::$fixtures[$test])) {
			$config = (new \ReflectionMethod($test, 'createMock'))->invoke($test, IAppConfig::class);
			$fixture = new self(new FileTypeGuard(new FileWritePolicy($config, (new \ReflectionMethod($test, 'createMock'))->invoke($test, IDBConnection::class))));
			$config->method('hasKey')->willReturnCallback(static fn (): bool => $fixture->value !== null || $fixture->reads !== []);
			$config->method('getValueString')->willReturnCallback(static function () use ($fixture): string {
				$fixture->count++;
				return json_encode(array_shift($fixture->reads) ?? $fixture->value, JSON_THROW_ON_ERROR);
			});
			self::$fixtures[$test] = $fixture;
		}
		return self::$fixtures[$test];
	}

	/**
	 * @param TestCase $test Owner of fixture and mocks.
	 * @return FileTypeGuard Real guard used by both existing regressions and new policy tests.
	 */
	public static function guard(TestCase $test): FileTypeGuard {
		return self::forTest($test)->guard;
	}
}
