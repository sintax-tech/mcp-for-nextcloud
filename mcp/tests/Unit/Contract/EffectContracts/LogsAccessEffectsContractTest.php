<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\GrantsController;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of `grants#updateLogsAccess` (RE-01): the groups whose members pass the role gate of the logs module,
 * through the real {@see GrantsController} and LogsAccess of {@see GrantsEffectsRig}. The effect is the stored group
 * list and its version, and whether a member of the group passes the gate afterwards.
 */
final class LogsAccessEffectsContractTest extends TestCase {
	/** @return void save, the refusals that write nothing, and a save that empties the list */
	public function testGroupsThatMayReadTheServerLog(): void {
		$rig = new GrantsEffectsRig($this);
		$rig->fx->addUser('carla', 'Carla', '', true, ['sales']);
		$state = fn (): array => ['logs' => $rig->fx->logsAccess()->state(), 'carla passes the gate' => $rig->fx->logsAccess()->permits('carla'),
			'revision' => $rig->revision()];
		$transcript = new ControllerTranscript($this, 'grants.logs-access', $state);
		$call = static fn (GrantsController $controller) => $controller->updateLogsAccess();
		$steps = [
			['groups not a list: 400', ['groups' => 'sales', 'previous' => [], 'version' => 0], false],
			['negative version: 400', ['groups' => ['sales'], 'previous' => [], 'version' => -1], false],
			['without expectedRevision: 428', ['groups' => ['sales'], 'previous' => [], 'version' => 0, 'expectedRevision' => null], false],
			['outdated list version: 409', ['groups' => ['sales'], 'previous' => [], 'version' => 7], false],
			['writer unusable: 503', ['groups' => ['sales'], 'previous' => [], 'version' => 0], true],
		];
		foreach ($steps as [$step, $body, $unusable]) {
			$before = $state();
			$rig->send($transcript, $step, 'grants#updateLogsAccess', $body, $call, $unusable);
			$this->assertEquals($before, $state(), $step . ': a refusal wrote something');
		}
		$saved = $rig->send($transcript, 'sales may read the log', 'grants#updateLogsAccess',
			['groups' => ['sales'], 'previous' => [], 'version' => 0], $call);
		$this->assertSame(200, $saved['status']);
		$this->assertTrue($state()['carla passes the gate'], 'a member of the saved group must pass the gate');
		$rig->send($transcript, 'emptied: administrators only', 'grants#updateLogsAccess',
			['groups' => [], 'previous' => ['sales'], 'version' => $rig->fx->logsAccess()->state()['version'] ?? 1], $call);
		$this->assertFalse($state()['carla passes the gate'], 'an emptied list must close the gate');
		$rig->register($transcript);
		$transcript->assertGolden();
	}
}
