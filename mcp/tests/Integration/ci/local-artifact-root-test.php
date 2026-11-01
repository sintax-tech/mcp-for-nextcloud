<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Scripts\Dev\SourceTrees;

require_once __DIR__ . '/../../../scripts/dev/src/SourceTrees.php';

/** Proves generated integration output cannot become input to the real recursive source reader. */
final class LocalArtifactRootCheck {
	/** @return void Creates an artifact through the harness path helper and checks the actual source reader. */
	public static function run(): void {
		$root = dirname(__DIR__, 4);
		$artifacts = trim((string)shell_exec('sh ' . escapeshellarg(__DIR__ . '/local-artifact-root.sh')));
		if ($artifacts !== $root . '/.mcp-it-runs') {
			throw new RuntimeException('Run artifacts must live at the checkout root, outside mcp/');
		}
		$run = $artifacts . '/reader-proof-' . bin2hex(random_bytes(6));
		mkdir($run, 0700, true);
		$marker = bin2hex(random_bytes(32));
		try {
			file_put_contents($run . '/generated.php', $marker);
			$tree = SourceTrees::fromDirectory($root . '/mcp', ['appinfo', 'lib', 'scripts', 'templates', 'tests', 'js']);
			if (!isset($tree['lib/Service/GrantPolicy.php'], $tree['tests/Integration/Files/DeleteFileTest.php'])) {
				throw new RuntimeException('The proof must include real production and integration source files');
			}
			if (in_array($marker, $tree, true)) {
				throw new RuntimeException('The source reader ingested generated integration output');
			}
			exec('git -C ' . escapeshellarg($root) . ' check-ignore --quiet ' . escapeshellarg($run . '/generated.php'), $lines, $status);
			if ($status !== 0) {
				throw new RuntimeException('Run artifacts must be ignored by Git');
			}
			echo "Artifact root: outside app; real source files retained; generated output excluded; Git ignored\n";
		} finally {
			unlink($run . '/generated.php');
			rmdir($run);
		}
	}
}

LocalArtifactRootCheck::run();
