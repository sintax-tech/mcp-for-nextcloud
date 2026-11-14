<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Availability;

use OCA\Mcp\Modules\Availability\AvailabilityModule;
use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The absence plan is text the person can read, and stored text stays inert. */
final class AvailabilityPlanRendererTest extends TestCase {
	use AssertsReadablePlans;

	/** @return void the one write of this module renders its own plan, as an update of a stored absence with a replacement */
	public function testEveryWriteHasAReadablePlan(): void {
		$module = new AvailabilityModule($this->createMock(ContainerInterface::class));
		$this->assertEveryWriteToolHasAReadablePlan($module, [
			'availability_set_absence' => [
				'action' => 'availability_set_absence',
				'mode' => 'update',
				'before' => ['first_day' => '2027-01-10', 'last_day' => '2027-01-12', 'status' => 'Away'],
				'after' => [
					'first_day' => '2027-02-01',
					'last_day' => '2027-02-03',
					'timezone' => 'America/Sao_Paulo',
					'starts_at' => '2027-02-01T00:00:00-03:00',
					'ends_at' => '2027-02-03T23:59:00-03:00',
					'status' => 'Vacation',
					'message' => 'Ask Carol',
					'replacement' => ['user_id' => 'carol', 'display_name' => 'Carol'],
				],
				'recoverable' => false,
				'notices' => ['The calendar will show the change.'],
				'message' => 'Nothing was changed.',
			],
		]);
	}

	/** @return void a first absence has no current one, and no replacement is named as none */
	public function testACreationWithoutReplacementIsReadable(): void {
		$module = new AvailabilityModule($this->createMock(ContainerInterface::class));
		$text = $module->renderPlan('availability_set_absence', [
			'mode' => 'create',
			'before' => null,
			'after' => ['first_day' => '2027-02-01', 'last_day' => '2027-02-03', 'timezone' => 'UTC',
				'starts_at' => '2027-02-01T00:00:00+00:00', 'ends_at' => '2027-02-03T23:59:00+00:00', 'status' => 'Vacation', 'message' => 'Back soon', 'replacement' => null],
			'notices' => [],
		]);
		self::assertIsString($text);
		self::assertStringContainsString('Replacement: none', $text);
		self::assertStringNotContainsString('Currently:', $text);
	}
}
