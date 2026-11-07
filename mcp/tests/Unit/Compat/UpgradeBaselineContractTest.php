<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Compat;

use OCA\Mcp\Tests\Integration\Compat\UpgradeBaseline;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Integration/Compat/LegacyCatalogContract.php';
require_once __DIR__ . '/../../Integration/Compat/UpgradeBaseline.php';

/** Upgrade fixture guards cannot turn removals, unknown additions or malformed input into passing comparisons. */
final class UpgradeBaselineContractTest extends TestCase {
	/** @return void All legacy operations remain exact and only the reviewed 1.2.2 and approved 1.3.0 F00 additions are accepted. */
	public function testCatalogRecognizesTheExplicitAdditionAndRejectsOtherChanges(): void {
		LegacyCatalogAssertions::catalog();
	}

	/** @return void known snapshots are distinct and malformed or unexpected checkpoints are never silently skipped */
	public function testOnlyRecognizedSnapshotStructuresAreAccepted(): void {
		$old = ['appVersion' => '1.0.8', 'catalog' => [], 'plan' => [], 'raw' => [], 'answers' => [], 'tokens' => []];
		$new = ['baseline' => '1.2.0', 'snapshot' => ['rows' => [], 'auth' => []]];
		self::assertSame('1.0.8', UpgradeBaseline::recognize($old));
		self::assertSame('1.2.0', UpgradeBaseline::recognize($new));
		foreach ([[], ['baseline' => '1.2.0'], ['appVersion' => '1.0.8'], [...$old, 'appVersion' => 'unknown'], [...$new, 'snapshot' => null]] as $invalid) {
			try { UpgradeBaseline::recognize($invalid); self::fail('Malformed checkpoint accepted'); } catch (\UnexpectedValueException) { }
		}
	}
	/** @return void file/JSON failures cannot become skips; only the other recognized baseline is intentionally skipped */
	public function testFileBoundaryFailsClosedAndSkipsOnlyTheOtherRecognizedBaseline(): void {
		$mode = getenv('MCP_IT_MODE');
		$path = getenv('MCP_IT_SNAPSHOT');
		$file = tempnam(sys_get_temp_dir(), 'mcp-upgrade-');
		try {
			putenv('MCP_IT_MODE=upgrade');
			putenv('MCP_IT_SNAPSHOT=' . $file);
			$old = ['appVersion' => '1.0.8', 'catalog' => [], 'plan' => [], 'raw' => [], 'answers' => [], 'tokens' => []];
			$new = ['baseline' => '1.2.0', 'snapshot' => ['rows' => [], 'auth' => []]];
			file_put_contents($file, json_encode($new));
			self::assertSame($new, UpgradeBaseline::forVersion('1.2.0'));
			foreach ([['{invalid', \JsonException::class], ['{}', \UnexpectedValueException::class], [json_encode($old), \PHPUnit\Framework\SkippedWithMessageException::class]] as [$raw, $expected]) {
				file_put_contents($file, $raw);
				try { UpgradeBaseline::forVersion('1.2.0'); self::fail('Invalid checkpoint accepted'); }
				catch (\Throwable $error) { self::assertSame($expected, $error::class); }
			}
			unlink($file);
			try { UpgradeBaseline::forVersion('1.2.0'); self::fail('Missing checkpoint accepted'); }
			catch (\Throwable $error) { self::assertSame(\RuntimeException::class, $error::class); }
		} finally {
			if (is_file($file)) { unlink($file); }
			putenv($mode === false ? 'MCP_IT_MODE' : 'MCP_IT_MODE=' . $mode);
			putenv($path === false ? 'MCP_IT_SNAPSHOT' : 'MCP_IT_SNAPSHOT=' . $path);
		}
	}

	/** @return void Every new default is checked and every legacy answer remains observable. */
	public function testMatrixChecksTheNewCellBeforeRemovingOnlyThatCell(): void {
		LegacyCatalogAssertions::matrix();
		ScenePolicyAssertions::unchanged($this);
		ServiceSwitchAssertions::roundTrip($this);
		SnapshotClientAssertions::trusted($this);
	}

}
