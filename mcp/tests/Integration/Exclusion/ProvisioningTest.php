<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCP\App\IAppManager;
use OCP\Server;

/**
 * The proof starts from what the cell really provides. A part that could not be provisioned fails here by name, with
 * the provisioning log in the cell's artifact, instead of leaving a scenario silently uncovered (V5 is mandatory).
 */
final class ProvisioningTest extends ExclusionTestCase {
	public function testNothingFailedToProvision(): void {
		$failures = trim((string)getenv('MCP_IT_PROVISION_FAILURES'));
		$this->assertSame('', $failures, "Provisioning failed for: $failures (see provision.log in the artifact of this cell)");
	}

	public function testTheAppsTheScenariosNeedAreEnabled(): void {
		$apps = Server::get(IAppManager::class);
		foreach (['groupfolders', 'files_external', 'spreed', 'notes', 'files_sharing', 'files_versions'] as $app) {
			$this->assertTrue($apps->isEnabledForAnyone($app), "$app must be enabled in this cell");
		}
	}

	public function testEveryPlaceAFileCanLiveIsInTheTreeOfTheFixtureUsers(): void {
		foreach ([self::EXTERNAL, self::ALIAS, self::NARROW] as $mount) {
			foreach ([self::ALICE, self::BOB] as $uid) {
				$this->assertTrue($this->userFolder($uid)->nodeExists($mount), "$mount is mounted for $uid");
			}
		}
		$this->assertTrue($this->userFolder(self::ALICE)->nodeExists($this->groupfolder()), 'the groupfolder reaches its group');
		$this->assertFalse($this->userFolder(self::BOB)->nodeExists($this->groupfolder()), 'and nobody outside it');
	}

	public function testBothLoginsReachTheEndpointAsTheirOwner(): void {
		foreach (['basic', 'bearer'] as $kind) {
			$this->assertSame(200, $this->mcp(self::ALICE, $kind)->listStatus(), "$kind login of alice");
			$this->assertSame(200, $this->mcp(self::BOB, $kind)->listStatus(), "$kind login of bob");
		}
	}
}
