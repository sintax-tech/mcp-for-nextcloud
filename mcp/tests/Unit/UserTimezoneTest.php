<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Service\UserTimezone;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Covers the precedence of the user's timezone: own preference, system default, PHP default.
 */
final class UserTimezoneTest extends TestCase {
	public function testUserPreferenceWins(): void {
		self::assertSame('America/Sao_Paulo', $this->zone('America/Sao_Paulo', 'Europe/Berlin')->getName());
	}

	public function testUnknownPreferenceFallsBackToTheSystemDefault(): void {
		self::assertSame('Europe/Berlin', $this->zone('Not/AZone', 'Europe/Berlin')->getName());
	}

	public function testNothingConfiguredFallsBackToPhp(): void {
		self::assertSame(date_default_timezone_get(), $this->zone('', '')->getName());
	}

	/**
	 * @param string $user value of core/timezone for the user
	 * @param string $system value of default_timezone in config.php
	 * @return \DateTimeZone zone resolved for alice
	 */
	private function zone(string $user, string $system): \DateTimeZone {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with('alice', 'core', 'timezone', '')->willReturn($user);
		$config->method('getSystemValueString')->with('default_timezone', '')->willReturn($system);

		return (new UserTimezone($config))->forUser('alice');
	}
}
