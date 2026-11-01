<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The connections script during the RE-11 → RE-12 exception: the admin bundle takes it from src/shared/connections.js,
 * while the personal page still loads js/connections.js on its own. Until RE-12 moves the personal page to its bundle
 * and removes js/connections.js, the two copies must stay byte for byte the same, so both pages run the same code.
 */
final class SharedConnectionsCopyTest extends TestCase {
	/**
	 * The two copies are identical while the personal page still loads js/connections.js.
	 *
	 * @return void
	 */
	public function testThePersonalCopyMatchesTheSharedSource(): void {
		$app = dirname(__DIR__, 3);
		$personal = (string)file_get_contents($app . '/templates/personal.php');
		if (!str_contains($personal, "addScript('mcp', 'connections')")) {
			$this->assertFileDoesNotExist($app . '/js/connections.js', 'RE-12: once the personal page stops loading it, js/connections.js goes away');
			return;
		}
		$this->assertFileEquals($app . '/src/shared/connections.js', $app . '/js/connections.js');
	}
}
