<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesSurface;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The `collectives.native` probe: the version range per Nextcloud major, the enabled app and the proven shape, every
 * condition alone enough to hide the tools; and what the registry makes of a probe that throws.
 */
final class CollectivesCapabilitiesTest extends TestCase {
	/**
	 * @param string $version installed Collectives version, '' when not installed
	 * @param bool $enabled whether it is enabled for anyone
	 * @return IAppManager the app manager of that installation
	 */
	private function apps(string $version, bool $enabled = true): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($version);
		$apps->method('isEnabledForAnyone')->willReturn($enabled);
		return $apps;
	}

	/** @return iterable<string, array{0:int, 1:string, 2:bool}> major, version and the expected answer */
	public static function versions(): iterable {
		yield 'NC32 at its minimum' => [32, '4.0.0', true];
		yield 'NC32 below it' => [32, '3.6.2', false];
		yield 'NC33 at 4.4.0' => [33, '4.4.0', true];
		yield 'NC34 below its minimum' => [34, '4.3.0', false];
		yield 'NC34 at its minimum' => [34, '4.4.0', true];
		yield 'NC35 below its minimum' => [35, '4.4.0', false];
		yield 'NC35 at its minimum' => [35, '4.5.0', true];
		yield 'NC35 last proven' => [35, '4.7.1', true];
		yield 'at the ceiling' => [35, '4.8.0', false];
		yield 'above the ceiling' => [35, '5.0.0', false];
		yield 'unknown major' => [36, '4.7.1', false];
		yield 'not installed' => [35, '', false];
	}

	/**
	 * @param int $major Nextcloud major
	 * @param string $version installed Collectives version
	 * @param bool $expected whether the tools exist
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testVersionRangePerMajor(int $major, string $version, bool $expected): void {
		$this->assertSame($expected, (new CollectivesCapabilities($this->apps($version), $major, static fn (): bool => true))->supports('collectives.native'));
	}

	/** @return void a disabled app, an incomplete shape and an unknown key are each false */
	public function testDisabledAppShapeAndUnknownKey(): void {
		$this->assertFalse((new CollectivesCapabilities($this->apps('4.7.1', false), 35, static fn (): bool => true))->supports('collectives.native'));
		$this->assertFalse((new CollectivesCapabilities($this->apps('4.7.1'), 35, static fn (): bool => false))->supports('collectives.native'));
		$this->assertFalse((new CollectivesCapabilities($this->apps('4.7.1'), 35, static fn (): bool => true))->supports('collectives.other'));
		$this->assertSame(['collectives.native'], (new CollectivesCapabilities($this->apps('4.7.1'), 35))->keys());
	}

	/** @return void the default shape check reads the real surface: complete over the doubles, incomplete without a member */
	public function testDefaultShapeIsTheSurface(): void {
		CollectivesScene::reset($this);
		$this->assertTrue((new CollectivesCapabilities($this->apps('4.7.1'), 35))->supports('collectives.native'));
		$this->assertTrue((new CollectivesSurface())->supported());
		$this->assertSame(CollectivesSurface::SEARCH_WITHOUT_LIMIT, (new CollectivesSurface())->searchArity());
		$this->assertNull((new CollectivesSurface(CollectivesSurface::MEMBERS, 'OCA\\Collectives\\Service\\Nothing'))->searchArity());
	}

	/** @return void a probe that throws is false for the request, logged once */
	public function testThrowingProbeIsFalse(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$probe = new CollectivesCapabilities($this->apps('4.7.1'), 35, static fn (): bool => throw new \RuntimeException('boom'));
		$registry = new CapabilityRegistry([$probe], $logger);
		$this->assertFalse($registry->supports('collectives.native'));
		$this->assertFalse($registry->supports('collectives.native'));
	}
}
