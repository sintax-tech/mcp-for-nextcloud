<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Compat;

use OCA\Mcp\OAuth\Clients\ClientAdmission;
use OCA\Mcp\Tests\Integration\Compat\SnapshotProbe;
use OCA\Mcp\Tests\Unit\OAuth\CimdRevocationRig;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/** Connects the legacy snapshot's client choice to the approved CIMD admission and refresh contract. */
final class SnapshotClientAssertions {
	/**
	 * @param TestCase $test Existing upgrade contract test supplying official interface doubles.
	 * @return void The old never-trusted host stays refused; the fixture's trusted client authenticates and refreshes.
	 */
	public static function trusted(TestCase $test): void {
		$rig = new CimdRevocationRig($test);
		Assert::assertFalse(ClientAdmission::admits($rig->table->mapper, 'https://mcp-it.example/snapshot-client.json', '1'));
		Assert::assertTrue(ClientAdmission::admits($rig->table->mapper, SnapshotProbe::CLIENT, '1'));
		$issued = $rig->issue(SnapshotProbe::CLIENT);
		Assert::assertSame('alice', $rig->bearer->authenticate('Bearer ' . $issued['access_token'], CimdRevocationRig::RESOURCE)?->getUID());
		$refreshed = $rig->tokens->refresh(['client_id' => SnapshotProbe::CLIENT, 'refresh_token' => $issued['refresh_token']]);
		Assert::assertSame('alice', $rig->bearer->authenticate('Bearer ' . $refreshed['access_token'], CimdRevocationRig::RESOURCE)?->getUID());
		Assert::assertNull($rig->bearer->authenticate('Bearer ' . $issued['access_token'], CimdRevocationRig::RESOURCE));
	}
}
