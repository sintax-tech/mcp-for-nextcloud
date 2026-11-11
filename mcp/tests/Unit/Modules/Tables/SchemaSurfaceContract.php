<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\TablesWriteCapabilities;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/** Explicit coverage for optional schema APIs; existing read/row source contracts stay unchanged. */
final class SchemaSurfaceContract {
	/**
	 * @param TestCase $test Owner of assertions and mocks.
	 * @param string $class New optional native class.
	 * @param string $major Existing contract major.
	 * @return bool True only for an explicitly covered new schema API case.
	 */
	public static function handles(TestCase $test, string $class, string $major): bool {
		$data = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/tables/schema-api-surfaces.json'), true, 512, JSON_THROW_ON_ERROR);
		if (!in_array($class, $data['classes'], true)) return false;
		$manifest = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/tables/schema-api.json'), true, 512, JSON_THROW_ON_ERROR);
		$mock = new \ReflectionMethod($test, 'createMock');
		foreach ($data['pins'] as $pin => $fixture) {
			$test::assertSame($manifest['sources'][$pin]['archiveSha256'], $fixture['archiveSha256']);
			$test::assertNotEmpty($fixture['surfaces'][$class], $class . ' lacks a proven native surface at ' . $pin);
			$apps = $mock->invoke($test, IAppManager::class);
			$apps->method('getAppVersion')->willReturn($pin);
			$apps->method('isEnabledForAnyone')->willReturn(true);
			$probe = new TablesWriteCapabilities($apps, (int)$major, static fn (): bool => true);
			$expected = in_array($major, ['34', '35'], true);
			$test::assertSame($expected, $probe->supports('tables.native_schema_api'));
			$test::assertSame($expected, $probe->supports('tables.native_sharing_api'));
		}
		return true;
	}
}
