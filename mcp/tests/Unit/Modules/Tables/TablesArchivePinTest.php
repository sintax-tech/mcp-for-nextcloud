<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use PHPUnit\Framework\TestCase;

/** Runs only the source-only shell selector, without containers, downloads or changing any installed app. */
final class TablesArchivePinTest extends TestCase {
	/** @return void Default cell pins remain unchanged, while only NC35 accepts the explicit 2.4.0 archive. */
	public function testDefaultsAndArchiveOptInAreBounded(): void {
		foreach ([34 => 'v2.2.2', 35 => 'v2.3.2'] as $major => $pin) {
			[$status, $output] = $this->select($major, '');
			self::assertSame(0, $status);
			self::assertStringStartsWith($pin . ' ', $output);
		}
		foreach ([34, 36] as $major) {
			[$status, $output] = $this->select($major, '/synthetic/tables-v2.4.0.tar.gz');
			self::assertNotSame(0, $status);
			self::assertSame('', $output);
		}
		[$status, $output] = $this->select(35, '/synthetic/tables-v2.4.0.tar.gz');
		self::assertSame(0, $status);
		$fixture = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/tables/tables-2.4.0.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('v2.4.0 ' . $fixture['archiveSha256'], $output);
	}

	/**
	 * @param int $major Disposable cell major.
	 * @param string $archive Developer opt-in path, never opened by the selector.
	 * @return array{int,string} Exit status and selected release/hash.
	 */
	private function select(int $major, string $archive): array {
		$script = __DIR__ . '/../../../Integration/ci/tables/release.sh';
		$process = proc_open(['/bin/sh', '-c', '. "$1"; pin_release || exit 1; printf "%s %s" "$TABLES_TAG" "$TABLES_SHA"', 'tables-pin-test', $script],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MCP_IT_NC' => (string)$major, 'MCP_IT_TABLES_ARCHIVE' => $archive]);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output];
	}
}
