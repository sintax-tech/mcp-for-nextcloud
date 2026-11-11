<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\SchemaApiShape;
use OCA\Mcp\Modules\Tables\Integration\TablesWriteCapabilities;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/** Additive view fields and serialization dependencies must be mandatory in the new profile and sharing probe. */
final class Tables240ShapeTest extends TestCase {
	/** @return void Removing either 2.4.0 property requirement cannot leave the shape contract green. */
	public function testOnlyTheNewProfileRequiresLayoutAndCardFields(): void {
		$viewFields = static fn (string $pin): array => array_values(array_map(static fn (array $entry): string => $entry[1],
			array_filter(SchemaApiShape::members($pin), static fn (array $entry): bool => $entry[0] === 'OCA\\Tables\\Db\\View')));
		self::assertContains('@layout', $viewFields('2.4.0'));
		self::assertContains('@viewSettings', $viewFields('2.4.0'));
		foreach (['1.0.0', '2.0.0', '2.2.2', '2.3.2'] as $pin) {
			self::assertNotContains('@layout', $viewFields($pin), $pin);
			self::assertNotContains('@viewSettings', $viewFields($pin), $pin);
		}
	}

	/** @return void Sharing also fails if a view serialization dependency disappears, regardless of its schema role. */
	public function testMissingViewModelsCloseSharingAsWellAsSchema(): void {
		foreach (['OCA\\Tables\\Model\\ViewSettings', 'OCA\\Tables\\Constants\\ViewLayout'] as $missing) {
			$apps = $this->createMock(IAppManager::class);
			$apps->method('getAppVersion')->willReturn('2.4.0');
			$apps->method('isEnabledForAnyone')->willReturn(true);
			$probe = new TablesWriteCapabilities($apps, 35, static fn (array $entry): bool => $entry[0] !== $missing);
			self::assertFalse($probe->supports('tables.native_sharing_api'), $missing);
			self::assertFalse($probe->supports('tables.native_schema_api'), $missing);
		}
	}
}
