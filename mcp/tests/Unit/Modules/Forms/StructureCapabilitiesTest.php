<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Modules\Forms\Integration\StructureCapabilities;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/** Probe forms.native_structure_api: exact pins, known majors, app enabled and the complete native shape. */
final class StructureCapabilitiesTest extends TestCase {
	/** Members the probe must check, one by one. */
	private const MEMBERS = [
		['OCA\\Forms\\Controller\\ApiController', 'newQuestion'], ['OCA\\Forms\\Controller\\ApiController', 'reorderQuestions'],
		['OCA\\Forms\\Controller\\ApiController', 'newOption'], ['OCA\\Forms\\Controller\\ApiController', 'reorderOptions'],
		['OCA\\Forms\\Controller\\ApiController', 'deleteQuestion'], ['OCA\\Forms\\Controller\\ApiController', 'deleteOption'],
		['OCA\\Forms\\Controller\\ApiController', 'updateQuestion'], ['OCA\\Forms\\Service\\FormsService', 'getFormIfAllowed'],
		['OCA\\Forms\\Service\\FormsService', 'isFormArchived'], ['OCA\\Forms\\Service\\FormsService', 'areExtraSettingsValid'],
		['OCA\\Forms\\Constants', 'ANSWER_TYPES'],
	];

	/** Valid case, every pin and only the declared key. @return void */
	public function testSupportedPins(): void {
		foreach (['5.3.0', '5.3.7', '5.4.0', '5.4.1', '5.4.2'] as $version) {
			self::assertTrue($this->probe($version)->supports(StructureCapabilities::KEY), $version);
		}
		self::assertSame([StructureCapabilities::KEY], $this->probe('5.4.1')->keys());
		self::assertFalse($this->probe('5.4.1')->supports('forms.other'));
	}

	/** Versions off the pins (below, between, above), unknown major and disabled app. @return void */
	public function testVersionsMajorsAndEnablement(): void {
		foreach (['5.2.10', '5.3.1', '5.4.3', '5.5.0', '6.0.0', ''] as $version) {
			self::assertFalse($this->probe($version)->supports(StructureCapabilities::KEY), $version);
		}
		foreach ([31, 36] as $major) {
			self::assertFalse($this->probe('5.4.1', $major)->supports(StructureCapabilities::KEY), (string)$major);
		}
		self::assertFalse($this->probe('5.4.1', 35, false)->supports(StructureCapabilities::KEY));
	}

	/** Each missing native member alone turns the probe off; an exception propagates to the registry, which fails closed. @return void */
	public function testIncompleteShapeAndException(): void {
		foreach (self::MEMBERS as $missing) {
			$probe = $this->probe('5.4.1', 35, true, static fn (string $class, string $member): bool => [$class, $member] !== $missing);
			self::assertFalse($probe->supports(StructureCapabilities::KEY), implode('::', $missing));
		}
		$probe = $this->probe('5.4.1', 35, true, static fn (): bool => throw new \RuntimeException('autoload'));
		$this->expectException(\RuntimeException::class);
		$probe->supports(StructureCapabilities::KEY);
	}

	/**
	 * @param string $version Forms version
	 * @param int $major Nextcloud major
	 * @param bool $enabled app enabled for someone
	 * @param callable|null $native introspection seam
	 * @return StructureCapabilities probe
	 */
	private function probe(string $version, int $major = 35, bool $enabled = true, ?callable $native = null): StructureCapabilities {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($version);
		$apps->method('isEnabledForAnyone')->willReturn($enabled);
		$apps->method('isInstalled')->willReturn($enabled);
		$apps->method('isEnabledForUser')->willReturn($enabled);
		return new StructureCapabilities($apps, $major, $native ?? static fn (): bool => true);
	}
}
