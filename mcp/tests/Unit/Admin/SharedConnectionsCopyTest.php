<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The connections script after RE-12: src/shared/connections.js is a fragment of both bundles and the old standalone
 * js/connections.js is gone, so the personal page loads only js/mcp-personal.js.
 */
final class SharedConnectionsCopyTest extends TestCase {
	/**
	 * The standalone copy no longer exists and the personal page does not ask for it.
	 *
	 * @return void
	 */
	public function testThePersonalCopyMatchesTheSharedSource(): void {
		$app = dirname(__DIR__, 3);
		$personal = (string)file_get_contents($app . '/templates/personal.php');
		$this->assertStringNotContainsString("addScript('mcp', 'connections')", $personal);
		$this->assertFileDoesNotExist($app . '/js/connections.js');
		$this->assertContains('shared/connections.js', file($app . '/src/settings.manifest', FILE_IGNORE_NEW_LINES));
		$this->assertContains('shared/connections.js', file($app . '/src/admin.manifest', FILE_IGNORE_NEW_LINES));
	}
}
