<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Ci;

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\TestedNextcloud;
use PHPUnit\Framework\TestCase;

/** Exercises the active workflow and source-only selectors without starting an instance or fetching archives. */
final class NextcloudCoverageTest extends TestCase {
	/** @return void Eight integration cells retain both modes, databases, pins and optional app fixtures. */
	public function testWorkflowKeepsExactlyTheApprovedCells(): void {
		$workflow = (string)file_get_contents(dirname(__DIR__, 5) . '/.github/workflows/integration.yml');
		preg_match_all("/- nc: '(\d+)'\s+db: (\w+)\s+nc_image: (\S+)\s+db_image: ([^\n]+)/", $workflow, $matches, PREG_SET_ORDER);
		self::assertSame(['34/mariadb', '35/mariadb', '35/pgsql', '35/sqlite'], array_map(static fn (array $m): string => $m[1] . '/' . $m[2], $matches));
		self::assertStringContainsString('mode: [fresh, upgrade]', $workflow);
		self::assertSame(TestedNextcloud::MAJORS, array_values(array_unique(array_column($matches, 1))));
		foreach ($matches as $cell) {
			self::assertMatchesRegularExpression('/^nextcloud:' . $cell[1] . '\.[0-9.]+-apache@sha256:[0-9a-f]{64}$/', $cell[3]);
			self::assertTrue($cell[2] === 'sqlite' ? trim($cell[4]) === "''" : preg_match('/@sha256:[0-9a-f]{64}$/', trim($cell[4])) === 1);
		}
		foreach (['DECK', 'MAIL', 'TABLES', 'FORMS', 'POLLS', 'COLLECTIVES'] as $app) {
			self::assertStringContainsString("MCP_IT_WITH_$app: '1'", $workflow);
		}
		$unit = (string)file_get_contents(dirname(__DIR__, 5) . '/.github/workflows/tests.yml');
		foreach (['8.2', '8.3', '8.4', '8.5', 'oracle', 'recordings', 'dev', 'supported', 'legacy-libxml'] as $kept) {
			self::assertStringContainsString($kept, $unit);
		}
	}

	/** @return void Default app selectors return the exact same approved pins for the remaining server majors. */
	public function testTablesSelectorKeepsRemainingPins(): void {
		foreach (['34' => 'v2.2.2', '35' => 'v2.3.2'] as $major => $tag) {
			[$status, $out] = $this->shell('. "$1"; pin_release; printf "%s" "$TABLES_TAG"', 'tables/release.sh', (string)$major);
			self::assertSame(0, $status);
			self::assertSame($tag, $out);
		}
	}

	/** @return void Forms and Polls test-env selectors keep their pins without touching Docker. */
	public function testFormsAndPollsSelectorsUseTheRemainingPins(): void {
		foreach (['34' => '5.4.2', '35' => '5.4.1'] as $major => $version) {
			[$status, $out] = $this->shell('sh "$1" test-env', 'forms/cell.sh', (string)$major);
			self::assertSame(0, $status, $out);
			self::assertStringContainsString('MCP_IT_FORMS_VERSION=' . $version, $out);
			[$status, $out] = $this->shell('sh "$1" test-env', 'polls/cell.sh', (string)$major);
			self::assertSame(0, $status, $out);
			self::assertStringContainsString('MCP_IT_POLLS_VERSION=9.3.0', $out);
		}
	}

	/** @return void Unknown majors fail before the local runner can start an engine. */
	public function testLocalRunnerRejectsAnUnknownMajorBeforeCreatingResources(): void {
		[$status, $out] = $this->shell('sh "$1" 36 mariadb fresh', 'run-local.sh', '36');
		self::assertSame(2, $status, $out);
		self::assertStringContainsString('nc 34|35', $out);
	}

	/**
	 * @param string $command Source-only shell command.
	 * @param string $script Existing script relative to Integration/ci.
	 * @param string $major Server major requested by the selector.
	 * @return array{int,string} Exit status and captured output.
	 */
	private function shell(string $command, string $script, string $major): array {
		$path = dirname(__DIR__, 3) . '/Integration/ci/' . $script;
		$process = proc_open(['/bin/sh', '-c', $command, 'coverage-test', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
			array_merge(getenv(), ['MCP_IT_NC' => $major, 'MCP_IT_TABLES_ARCHIVE' => '', 'RUNNER_TEMP' => sys_get_temp_dir()]));
		self::assertIsResource($process);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output];
	}
}
