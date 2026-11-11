<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Modules\Forms\FormsVersionProfiles;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeArguments;
use PHPUnit\Framework\TestCase;

/** Pins constructor and effect differences independently of the production profile table. */
final class FormsVersionProfilesTest extends TestCase {
	/** Preserves the production profile map and exercises only active source pins.
	 * @return void
	 */
	public function testExactProfiles(): void {
		$expected = [
			'5.3.0' => ['5.3.0', false, true],
			'5.3.7' => ['5.3.7', false, true],
			'5.4.0' => ['5.4', false, true],
			'5.4.1' => ['5.4', true, true],
			'5.4.2' => ['5.4', true, false],
		];
		self::assertSame(array_keys($expected), array_keys(FormsVersionProfiles::PROFILES));
		foreach (array_intersect_key($expected, \OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsSourcePins::all()) as $version => $fields) {
			self::assertSame($fields, array_values(FormsVersionProfiles::get($version)), $version);
			self::assertSame($fields[0], FormsVersionProfiles::constructorFamily($version));
		}
	}

	/** An unknown release cannot obtain constructor collaborators even when called outside the factory gate.
	 * @return void
	 */
	public function testUnknownVersionsRefuseBeforeResolution(): void {
		foreach (['5.4.3', '5.5.0', '5.4.2-dev', ''] as $version) {
			self::assertNull(FormsVersionProfiles::get($version));
			$get = static function (): object { self::fail('Unknown pin resolved a native dependency'); };
			try {
				CreateNativeArguments::submission($version, $get, $this->createMock(\OCP\IUserSession::class), new \stdClass());
				self::fail('Unknown constructor admitted');
			} catch (\InvalidArgumentException $error) {
				self::assertSame('Unproven Forms version', $error->getMessage());
			}
		}
	}
}
