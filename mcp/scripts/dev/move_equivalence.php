<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Token comparator of a move commit (commit (a) of a restructuring ticket).
 *
 * Usage: `php scripts/dev/move_equivalence.php [--ticket=RE-xx] [--app=mcp] <old-rev> <new-rev>`, run inside the
 * Git work tree. Both revisions are read from Git (the working tree is not used); the map is the
 * scripts/dev/fqcn-map.json of the new revision. With `--ticket`, every map entry of that ticket must have moved.
 * Exit code 0 when the range is equivalent, 1 with the problems on stderr, 2 on bad usage.
 *
 * Not compared, by design: whitespace between tokens, comments and PHPDoc, and the order of imports. A class
 * named only in PHPDoc (`@param`, `@var`) does not run; a stale one there is left to the human review of the move.
 * Dynamic class-name concatenation/interpolation and paths passed through heredocs or constants are not resolved.
 * These require runtime tests and human review; literal FQCN::identifier callables are checked.
 * Non-PHP tests may only replace whole path tokens using the actual file pairs; all other bytes must match.
 */

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use OCA\Mcp\Scripts\Dev\SourceTrees;

require __DIR__ . '/bootstrap.php';

// Both trees and the token streams of the large API fixtures do not fit in PHP's default 128 MB.
ini_set('memory_limit', '1G');

$rest = 0;
$options = getopt('', ['ticket:', 'app:'], $rest);
$revisions = array_slice($argv, $rest);
if ($options === false || count($revisions) !== 2) {
	fwrite(STDERR, "usage: php scripts/dev/move_equivalence.php [--ticket=RE-xx] [--app=mcp] <old-rev> <new-rev>\n");
	exit(2);
}
$app = (string)($options['app'] ?? 'mcp');
try {
	$before = SourceTrees::fromGit(__DIR__, $revisions[0], $app);
	$after = SourceTrees::fromGit(__DIR__, $revisions[1], $app);
	$map = FqcnMap::fromJson($after['scripts/dev/fqcn-map.json'] ?? throw new RuntimeException("{$revisions[1]} has no scripts/dev/fqcn-map.json"));
	$equivalence = new MoveEquivalence($before, $after, $map);
	$problems = $equivalence->check(isset($options['ticket']) ? (string)$options['ticket'] : null);
} catch (RuntimeException $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
printf("%d moved files compared\n", count($equivalence->pairs()));
foreach ($problems as $problem) {
	fwrite(STDERR, $problem . "\n");
}
exit($problems === [] ? 0 : 1);
