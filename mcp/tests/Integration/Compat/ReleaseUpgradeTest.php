<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;

/** The release upgrade retains real baseline data and its still-live credential. */
final class ReleaseUpgradeTest extends IntegrationTestCase {
	/** @return void compare the recognized release checkpoint before any later suite mutates policy or fixtures */
	public function testUpgradePreservesConfigurationGrantsCredentialAndLegacyBackup(): void {
		ReleaseUpgradeProof::assertPreserved();
	}
}
