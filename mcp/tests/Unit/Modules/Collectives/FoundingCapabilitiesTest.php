<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\FoundingCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\FoundingSurface;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `collectives.founding` probe: Nextcloud 34/35 in this coverage (D7), Teams enabled in the release of that major, the page
 * capability, and the proven native shape; each condition alone hides collectives_create_collective.
 */
final class FoundingCapabilitiesTest extends TestCase {
	/**
	 * @param int $major Nextcloud major
	 * @param string $teams installed Teams version, '' when missing
	 * @param bool $teamsEnabled whether Teams is enabled for anyone
	 * @param bool $native whether the page capability holds
	 * @param bool $shape whether the founding shape matches
	 * @return FoundingCapabilities the probe of that installation
	 */
	private function probe(int $major, string $teams = '35.0.0', bool $teamsEnabled = true, bool $native = true, bool $shape = true): FoundingCapabilities {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(static fn (string $app): string => $app === 'circles' ? $teams : '4.7.1');
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => $app !== 'circles' || $teamsEnabled);
		return new FoundingCapabilities($apps, new CollectivesCapabilities($apps, $major, static fn (): bool => $native), $major, static fn (): bool => $shape);
	}

	/** @return iterable<string, array{0:array{0:int, 1?:string, 2?:bool, 3?:bool, 4?:bool}, 1:bool}> the installation and the expected answer */
	public static function installations(): iterable {
		yield 'NC35 with Teams 35' => [[35, '35.0.0'], true];
		yield 'NC34 with Teams 34' => [[34, '34.0.0'], true];
		yield 'NC36: not proven' => [[36, '36.0.0'], false];
		yield 'Teams of another major' => [[35, '34.0.0'], false];
		yield 'Teams missing' => [[35, ''], false];
		yield 'Teams disabled' => [[35, '35.0.0', false], false];
		yield 'page capability negative' => [[35, '35.0.0', true, false], false];
		yield 'shape negative' => [[35, '35.0.0', true, true, false], false];
	}

	/**
	 * @param array{0:int, 1?:string, 2?:bool, 3?:bool, 4?:bool} $installation arguments of {@see self::probe()}
	 * @param bool $expected whether the tool exists
	 * @return void
	 */
	#[DataProvider('installations')]
	public function testEveryConditionHidesTheTool(array $installation, bool $expected): void {
		$probe = $this->probe(...$installation);
		$this->assertSame($expected, $probe->supports(FoundingCapabilities::KEY));
		$this->assertFalse($probe->supports('collectives.native'), 'another key is not this probe’s');
		$this->assertSame([FoundingCapabilities::KEY], $probe->keys());
	}

	/** @return void a native shape with any member missing is not supported */
	public function testTheShapeFailsClosed(): void {
		$this->assertFalse(FoundingSurface::supported([['OCA\\Circles\\Missing', 'stopSession', [], 'void']]));
		$this->assertFalse(FoundingSurface::supported());
	}
}
