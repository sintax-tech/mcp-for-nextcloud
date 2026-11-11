<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\Integration\{TablesWriteCapabilities, SchemaApiShape};
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Version ceilings, every consumed member, missing probes and probe failures stay fail-closed. */
final class TablesWriteCapabilitiesTest extends TestCase {
	/** @return void Every supported range is bounded; unproved families/prereleases remain hidden. */
	public function testVersionBoundaries(): void {
		foreach ([['2.2.3',35,false],
			['2.3.0',35,false],['2.3.1',35,true],['2.3.2',35,true],['2.3.3',35,false],['2.4.0',35,true],['2.4.0',34,true],['2.4.1',35,false],['2.4.0-rc.1',35,false],['2.3.2-rc.1',35,false],['2.0.0',34,false],] as [$version,$major,$allowed]) {
			$probe = $this->probe($version, $major);
			$this->assertSame($allowed, $probe->supports('tables.native_schema_api'), $version . '/' . $major);
		}
		$this->assertFalse($this->probe('2.3.2',35,false)->supports('tables.native_schema_api'));
	}
	/** @return void No individual missing native member is silently accepted. */
	public function testEachConsumedMemberIsRequired(): void {
		foreach (['2.2.2' => 34, '2.3.2' => 35, '2.4.0' => 35] as $profile => $major) {
			$members = SchemaApiShape::members($profile);
			foreach ($members as $missing) {
				$key = str_contains($missing[0], 'Share') ? 'tables.native_sharing_api' : 'tables.native_schema_api';
				$probe = $this->probe($profile, $major, true, static fn (array $entry): bool => $entry !== $missing);
				$this->assertFalse($probe->supports($key), $missing[0] . '::' . $missing[1]);
			}
		}
	}
	/** @return void Discovery contains probe exceptions and logs once per request. */
	public function testExceptionAndMissingProbe(): void {
		$logger = $this->createMock(LoggerInterface::class); $logger->expects($this->once())->method('warning');
		$probe = $this->probe('2.3.2',35,true,static function (): bool { throw new \RuntimeException('native unavailable'); });
		$registry = new CapabilityRegistry([$probe], $logger);
		$this->assertFalse($registry->supports('tables.native_schema_api'));
		$this->assertFalse($registry->supports('tables.native_schema_api'));
		$this->assertFalse((new CapabilityRegistry([], $this->createMock(LoggerInterface::class)))->supports('tables.native_schema_api'));
	}
	/** @return void Equal short names in different namespaces are incompatible signatures. */
	public function testFullyQualifiedNativeParameterTypesAreRequired(): void {
		$entry = [NativeTypeShapeControl::class, 'accept', [['name' => 'value', 'type' => 'DateTime', 'optional' => false]], 'DateTime'];
		$this->assertTrue(SchemaApiShape::matches($entry));
		$entry[2][0]['type'] = 'Other\\DateTime';
		$this->assertFalse(SchemaApiShape::matches($entry));
	}
	/**
	 * @param string $version Installed version.
	 * @param int $major Server.
	 * @param bool $on Enablement.
	 * @param \Closure|null $shape Member seam.
	 * @return TablesWriteCapabilities Probe without native services.
	 */
	private function probe(string $version, int $major, bool $on = true, ?\Closure $shape = null): TablesWriteCapabilities {
		$apps = $this->createMock(IAppManager::class); $apps->method('getAppVersion')->willReturn($version); $apps->method('isEnabledForAnyone')->willReturn($on);
		return new TablesWriteCapabilities($apps, $major, $shape ?? static fn (): bool => true);
	}
}

/** Test-only signature control; no native services are constructed. */
final class NativeTypeShapeControl {
	/**
	 * @param \DateTime $value Typed dependency.
	 * @return \DateTime Same dependency.
	 */
	public function accept(\DateTime $value): \DateTime { return $value; }
}
