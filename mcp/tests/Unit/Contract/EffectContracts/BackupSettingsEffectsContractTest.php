<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use PHPUnit\Framework\TestCase;

/**
 * Effect contract of `backup_settings#update` (RE-01): the backup folder and the copy switch, saved under the policy
 * transaction by the real controller and writer of {@see BackupSettingsRig}. The effect is the stored setting, the
 * revision and the policy events; every refusal leaves them as they were.
 */
final class BackupSettingsEffectsContractTest extends TestCase {
	/** @return void the refusals, a save, a second save that keeps the old folder reserved, and a stale page */
	public function testBackupFolderAndCopies(): void {
		$rig = new BackupSettingsRig($this);
		$transcript = new ControllerTranscript($this, 'settings.backup', static fn (): array => $rig->snapshot());
		$refusals = [
			['without expectedRevision: 428', ['folder' => 'Cópias MCP', 'copies' => true]],
			['copies not a boolean: 400', ['expectedRevision' => '0', 'folder' => 'Cópias MCP', 'copies' => 'false']],
			['a folder with a slash: 400', ['expectedRevision' => '0', 'folder' => 'a/b', 'copies' => true]],
			['a name the core refuses: 400', ['expectedRevision' => '0', 'folder' => 'core-rejects-this', 'copies' => true]],
			['a non-canonical revision: 400', ['expectedRevision' => '00', 'folder' => 'Cópias MCP', 'copies' => true]],
		];
		foreach ($refusals as [$step, $body]) {
			$this->refuse($rig, $transcript, $step, $body);
		}
		$this->refuse($rig, $transcript, 'writer unusable: 503', ['expectedRevision' => '0', 'folder' => 'Cópias MCP', 'copies' => true], true);
		$first = $this->send($rig, $transcript, 'folder Cópias MCP, copies on', ['expectedRevision' => '0', 'folder' => 'Cópias MCP', 'copies' => true]);
		$this->assertSame(200, $first['status']);
		$second = $this->send($rig, $transcript, 'folder Arquivo, copies off: the old name stays reserved',
			['expectedRevision' => '1', 'folder' => 'Arquivo', 'copies' => false]);
		$this->assertContains('Cópias MCP', $second['json']['reserved'] ?? [], 'a previous folder name must stay reserved');
		$this->refuse($rig, $transcript, 'stale page: 409', ['expectedRevision' => '1', 'folder' => 'Outra', 'copies' => true]);
		$transcript->assertGolden();
	}

	/**
	 * @param BackupSettingsRig $rig the wiring
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @param string $step what the step proves
	 * @param array<string, mixed> $body request parameters
	 * @param bool $unusable whether the policy writer is unusable during the request
	 * @return void
	 */
	private function refuse(BackupSettingsRig $rig, ControllerTranscript $transcript, string $step, array $body, bool $unusable = false): void {
		$before = $rig->snapshot();
		$answer = $this->send($rig, $transcript, $step, $body, $unusable);
		$this->assertNotSame(200, $answer['status'], $step);
		$this->assertEquals($before, $rig->snapshot(), $step . ': a refusal wrote something');
	}

	/**
	 * @param BackupSettingsRig $rig the wiring
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @param string $step what the step proves
	 * @param array<string, mixed> $body request parameters
	 * @param bool $unusable whether the policy writer is unusable during the request
	 * @return array<string, mixed> the recorded answer
	 */
	private function send(BackupSettingsRig $rig, ControllerTranscript $transcript, string $step, array $body, bool $unusable = false): array {
		$controller = $rig->controller($body);
		$call = static fn () => $controller->update();
		$response = $unusable ? UnusableWriter::during($rig->writer(), $call) : $call();
		return $transcript->record($step, ['route' => 'backup_settings#update', 'user' => 'root', 'body' => $body], $response);
	}
}
