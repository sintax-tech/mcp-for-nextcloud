<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use PHPUnit\Framework\TestCase;

/** Selects only recognized upgrade snapshots; corrupt or unexpected inputs are failures, never skips. */
final class UpgradeBaseline {
	/**
	 * @param array<string, mixed> $snapshot checkpoint written by a supported real baseline
	 * @return string recognized baseline version
	 * @throws \UnexpectedValueException for an unknown or malformed snapshot
	 */
	public static function recognize(array $snapshot): string {
		if (($snapshot['appVersion'] ?? null) === '1.0.8' && !isset($snapshot['baseline'])) {
			foreach (['catalog', 'plan', 'raw', 'answers', 'tokens'] as $key) {
				if (!is_array($snapshot[$key] ?? null)) {
					throw new \UnexpectedValueException('Malformed 1.0.8 upgrade snapshot');
				}
			}
			return '1.0.8';
		}
		if (self::isRelease($snapshot['baseline'] ?? null) && !isset($snapshot['appVersion']) && is_array($snapshot['snapshot'] ?? null)) {
			foreach (($snapshot['baseline'] === '1.2.0' ? ['rows', 'auth'] : ['rows', 'auth', 'receipts']) as $key) {
				if (!is_array($snapshot['snapshot'][$key] ?? null)) {
					throw new \UnexpectedValueException('Malformed release upgrade snapshot');
				}
			}
			return $snapshot['baseline'];
		}
		throw new \UnexpectedValueException('Unexpected upgrade snapshot baseline or structure');
	}

	/** @param mixed $version Candidate baseline identity. @return bool Whether the container-era probe supports it. */
	public static function isRelease(mixed $version): bool {
		return is_string($version) && (in_array($version, ['1.2.0', '1.2.5'], true)
			|| preg_match('/^1\.3\.0-rc\.[0-9]+$/D', $version) === 1);
	}

	/** @return string Recognized installed baseline from the harness checkpoint. */
	public static function version(): string {
		return self::recognize(self::read());
	}

	/** @return array<string, mixed> A recognized container-era checkpoint, using the existing other-baseline skip. */
	public static function forRelease(): array {
		$actual = self::version();
		return self::forVersion(self::isRelease($actual) ? $actual : '1.2.0');
	}

	/**
	 * @param string $version baseline this test is responsible for
	 * @return array<string, mixed> decoded snapshot for that baseline
	 * @throws \Throwable on absent, malformed or unexpected checkpoint data
	 */
	public static function forVersion(string $version): array {
		$snapshot = self::read();
		$actual = self::recognize($snapshot);
		if ($actual !== $version) {
			TestCase::markTestSkipped("Recognized $actual checkpoint is covered by its own upgrade test");
		}
		return $snapshot;
	}

	/** @return array<string, mixed> The harness checkpoint; absence, corruption and unknown identities fail closed. */
	private static function read(): array {
		if (getenv('MCP_IT_MODE') !== 'upgrade') {
			TestCase::markTestSkipped('Upgrade checkpoint is not part of a fresh cell');
		}
		$path = (string)getenv('MCP_IT_SNAPSHOT');
		if (!is_file($path)) {
			throw new \RuntimeException('Upgrade snapshot is missing');
		}
		$snapshot = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($snapshot)) {
			throw new \UnexpectedValueException('Upgrade snapshot must be an object');
		}
		self::recognize($snapshot);
		return $snapshot;
	}
}
