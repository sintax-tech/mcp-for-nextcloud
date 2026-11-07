<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\TeamsCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\TeamsSession;
use OCA\Mcp\Modules\Collectives\Integration\TeamsSurface;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `collectives.teams` probe: Nextcloud 33 to 35 only (D7), Teams enabled in the release of that major, the page
 * capability, and the proven native shape; each condition alone hides the member tools.
 */
final class TeamsCapabilitiesTest extends TestCase {
	/**
	 * @param int $major Nextcloud major
	 * @param string $teams installed Teams version, '' when missing
	 * @param bool $teamsEnabled whether Teams is enabled for anyone
	 * @param bool $native whether the page capability holds
	 * @param bool $shape whether the founding shape matches
	 * @return TeamsCapabilities the probe of that installation
	 */
	private function probe(int $major, string $teams = '35.0.0', bool $teamsEnabled = true, bool $native = true, bool $shape = true): TeamsCapabilities {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(static fn (string $app): string => $app === 'circles' ? $teams : '4.7.1');
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => $app !== 'circles' || $teamsEnabled);
		return new TeamsCapabilities($apps, new CollectivesCapabilities($apps, $major, static fn (): bool => $native), $major, static fn (): bool => $shape);
	}

	/** @return iterable<string, array{0:array{0:int, 1?:string, 2?:bool, 3?:bool, 4?:bool}, 1:bool}> the installation and the expected answer */
	public static function installations(): iterable {
		yield 'NC35 with Teams 35' => [[35, '35.0.0'], true];
		yield 'NC33 with Teams 33' => [[33, '33.0.0'], true];
		yield 'NC34 with Teams 34' => [[34, '34.0.0'], true];
		yield 'NC32: no identity read without writing (D7)' => [[32, '32.0.0'], false];
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
		$this->assertSame($expected, $probe->supports(TeamsCapabilities::KEY));
		$this->assertFalse($probe->supports('collectives.native'), 'another key is not this probe’s');
		$this->assertSame([TeamsCapabilities::KEY], $probe->keys());
	}

	/** @return void a native shape with any member missing is not supported */
	public function testTheShapeFailsClosed(): void {
		$this->assertFalse(TeamsSurface::supported([['OCA\\Circles\\Missing', 'stopSession', [], 'void']]));
		$this->assertFalse(TeamsSurface::supported([], [['OCA\\Circles\\Missing', 'LEVEL_OWNER', 9]]));
		$this->assertFalse(TeamsSurface::supported());
		$present = [[TeamsSession::class, 'seal', [], 'void']];
		$this->assertTrue(TeamsSurface::supported([], [[\DateTime::class, 'ATOM', 'Y-m-d\\TH:i:sP']], $present), 'a constant with the proven value');
		$this->assertFalse(TeamsSurface::supported([], [[\DateTime::class, 'ATOM', 'another meaning']], $present), 'a constant with another value');
	}

	/** @return void the based-on read that identifies a group needs one of its proven shapes, Circles 33/34 or 35 */
	public function testOneBasedOnShapeIsEnough(): void {
		$missing = ['OCA\\Circles\\Missing', 'getBasedOn', [], 'int'];
		$this->assertTrue(TeamsSurface::supported([], [], [$missing, [TeamsSession::class, 'seal', [], 'void']]), 'the second shape matches');
		$this->assertTrue(TeamsSurface::supported([], [], [[TeamsSession::class, 'seal', [], 'void'], $missing]), 'the first shape matches');
		$this->assertFalse(TeamsSurface::supported([], [], [$missing]), 'no shape matches');
		$this->assertFalse(TeamsSurface::supported([], [], []), 'no shape at all');
	}
}
