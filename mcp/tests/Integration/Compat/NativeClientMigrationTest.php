<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;

/**
 * The upgrade from the released baseline carries the native client's switch, which 1.0.8 kept in the app configuration
 * (ci/legacy-credentials.php turned it on through the baseline before `occ upgrade`), into the app's own table of
 * clients, which is the only source of admission from 1.1.0 on: on, generation 1, with no terminal step. The legacy
 * value is left where it was. Runs in the compat suite, before any other test can touch the switch.
 */
final class NativeClientMigrationTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_MODE') !== 'upgrade') {
			$this->markTestSkipped('Only an upgrade cell has the switch the baseline wrote');
		}
	}

	public function testTheNativeSwitchOfTheBaselineIsInTheTableAfterTheUpgrade(): void {
		NativeClientUpgradeProof::assertPreserved($this->openIndependentConnection());
	}
}
