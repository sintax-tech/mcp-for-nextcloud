<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\{MoveEquivalence, FrozenPathSites, FrozenPathCall, SourceTreeIndex, FrozenProduction, TokenNormalizer};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/** Frozen provenance complements token equality; no arbitrary variable or basename becomes proved. */
final class FrozenPathSitesTest extends TestCase {
	public function testAllFourReviewedSitesPassAndMissingProofsDoNot(): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees();
		self::assertSame([], (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21'));
		unset($before[FrozenPathSites::PATH], $after[FrozenPathSites::PATH]);
		$problems = (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21');
		self::assertCount(5, $problems);
		self::assertSame(4, count(array_filter($problems, static fn (string $p): bool => str_contains($p, 'path not proved'))));
	}

	/** @return iterable<string,array{string,string}> Equal mutations on both sides must still invalidate the frozen source. */
	public static function sourceMutants(): iterable {
		yield 'different parameter' => ['$body', '$other'];
		yield 'source file assignment' => ['return file_get_contents', '$body = __FILE__; return file_get_contents'];
		yield 'source directory assignment' => ['return file_get_contents', '$body = __DIR__; return file_get_contents'];
		yield 'parameter default' => ['string $body)', "string \$body = 'relative')"];
		yield 'extra include-path argument' => ['file_get_contents($body)', 'file_get_contents($body,true)'];
		yield 'named include-path argument' => ['file_get_contents($body)', 'file_get_contents($body,use_include_path:true)'];
		yield 'another method' => ['function write(', 'function other('];
		yield 'unlisted second call' => ['return file_get_contents', 'file_get_contents($body); return file_get_contents'];
		yield 'function alias' => ['class CheckoutWriteHandler', 'use function Evil\\file_get_contents; class CheckoutWriteHandler'];
		yield 'namespace override' => ['class CheckoutWriteHandler', 'function file_get_contents($p) { return $p; } class CheckoutWriteHandler'];
		yield 'different PathGuard alias' => ['Platform\\FileAccess\\PathGuard;', 'Other\\PathGuard;'];
	}

	#[DataProvider('sourceMutants')]
	public function testEqualButUnreviewedSourcesAreRejected(string $find, string $replace): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees(); $changed = 0;
		foreach ([$before, $after] as $side => $tree) {
			foreach ($tree as $path => $code) {
				if (str_ends_with($path, '.php') && str_contains($code, $find)) {
					$tree[$path] = str_replace($find, $replace, $code); $changed++;
				}
			}
			if ($side === 0) { $before = $tree; } else { $after = $tree; }
		}
		self::assertGreaterThanOrEqual(2, $changed);
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21'));
		self::assertStringContainsString(FrozenPathSites::PATH . ':', $problems);
		self::assertStringContainsString('path not proved', $problems);
	}

	public function testCallerChangesInvalidateEvenIdenticalInputTrees(): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees();
		foreach ($before as $path => $code) {
			if (str_contains($code, 'class Caller')) { $before[$path] = $after[$path] = str_replace('return $path;', "return 'changed';", $code); }
		}
		self::assertStringContainsString('caller/origin changed', implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21')));
	}

	/** @return iterable<string,array{string}> Manifest failures, including entries that cannot be consumed. */
	public static function manifestMutants(): iterable {
		foreach (['one-sided edit', 'missing before', 'missing after', 'malformed', 'duplicate', 'excess', 'unknown identity', 'unknown field', 'missing provenance', 'wrong ordinal', 'changed operand hash'] as $kind) { yield $kind => [$kind]; }
	}

	#[DataProvider('manifestMutants')]
	public function testInvalidOrUnconsumableManifestCannotGrantAProof(string $kind): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees(); $data = json_decode($before[FrozenPathSites::PATH], true);
		switch ($kind) {
			case 'one-sided edit': $after[FrozenPathSites::PATH] .= "\n"; break;
			case 'missing before': unset($before[FrozenPathSites::PATH]); break;
			case 'missing after': unset($after[FrozenPathSites::PATH]); break;
			case 'malformed': $before[FrozenPathSites::PATH] = $after[FrozenPathSites::PATH] = '{'; break;
			default:
				switch ($kind) {
					case 'duplicate': $data['sites'][3] = $data['sites'][0]; break;
					case 'excess': $data['sites'][] = $data['sites'][0]; break;
					case 'unknown identity': $data['sites'][0]['map_path'] = 'lib/Unknown.php'; break;
					case 'unknown field': $data['sites'][0]['allow_any'] = true; break;
					case 'missing provenance': $data['production'] = []; break;
					case 'wrong ordinal': $data['sites'][0]['ordinal'] = 2; break;
					case 'changed operand hash': $data['sites'][0]['occurrence']['operand_sha256'] = str_repeat('0', 64); break;
				}
				$before[FrozenPathSites::PATH] = $after[FrozenPathSites::PATH] = json_encode($data, JSON_THROW_ON_ERROR);
		}
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21'));
		self::assertStringContainsString(FrozenPathSites::PATH . ':', $problems);
		self::assertStringContainsString('path not proved', $problems);
	}

	public function testEveryActiveProofMustBeConsumedOnBothSidesAtItsExactLocation(): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees(); $renames = [];
		foreach ($map->entries as $entry) { if ($entry['target'] !== 'stays') { $renames[$entry['fqcn']] = $entry['target']; } }
		$index = new SourceTreeIndex($before);
		$descriptorPath = 'scripts/dev/another-ticket-sites.json';
		$before[$descriptorPath] = $before[FrozenPathSites::PATH]; $after[$descriptorPath] = $after[FrozenPathSites::PATH];
		unset($before[FrozenPathSites::PATH], $after[FrozenPathSites::PATH]); $certificates[0]['path'] = $descriptorPath;
		$proof = new FrozenPathSites($before, $after, $pairs, $renames, $map, $index, new SourceTreeIndex($after), 'RE-21', $certificates);
		foreach ($proof->problems() as $problem) { self::assertStringStartsWith($descriptorPath . ':', $problem); }
		self::assertCount(8, $proof->problems()); $path = array_key_first($pairs);
		$source = (new TokenNormalizer($index))->normalize($before[$path], $renames, $path, $pairs); $diagnostic = $source->located[0];
		self::assertFalse($proof->accepts(0, $path, $source, [$diagnostic[0], 'other path not proved', $diagnostic[2]]));
		self::assertTrue($proof->accepts(0, $path, $source, $diagnostic));
		self::assertFalse($proof->accepts(0, $path, $source, $diagnostic));
		self::assertCount(7, $proof->problems());
		$after[$descriptorPath] = '{';
		$invalid = new FrozenPathSites($before, $after, $pairs, $renames, $map, $index, new SourceTreeIndex($after), 'RE-21', $certificates);
		self::assertStringStartsWith($descriptorPath . ':', $invalid->problems()[0]);
	}

	public function testAnUnlistedFifthSourceStillFails(): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees();
		$namespace = implode('\\', ['OCA', 'Mcp', 'Tools', 'Files']);
		$old = implode('/', ['lib', 'Tools', 'Files', 'Unreviewed']) . '.php'; $new = str_replace('/Tools/', '/Modules/', $old);
		$before[$old] = "<?php namespace $namespace; class Unreviewed { function read(string \$path) { return dirname(\$path); } }";
		$after[$new] = str_replace('\\Tools\\', '\\Modules\\', $before[$old]);
		$entries = [...$map->entries, ['path' => $old, 'fqcn' => $namespace . '\\Unreviewed', 'target' => str_replace('\\Tools\\', '\\Modules\\', $namespace) . '\\Unreviewed', 'owner' => 'Modules\\Files', 'ticket' => 'RE-21']];
		$map = new \OCA\Mcp\Scripts\Dev\FqcnMap($entries, $map->prefix, $map->root);
		$problems = (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21');
		self::assertNotEmpty($problems);
		self::assertStringContainsString('Unreviewed', implode("\n", $problems));
		self::assertStringContainsString('path not proved', $problems[0]);
	}

	public function testSourceLocationInTheCallerIsRefusedToo(): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees();
		foreach ($before as $path => $code) {
			if (str_contains($code, 'class Caller')) { $before[$path] = $after[$path] = str_replace('return $path;', 'return __FILE__;', $code); }
		}
		self::assertStringContainsString('caller/origin changed', implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21')));
	}

	public function testBuiltinAndParameterChecksDoNotRelyOnlyOnTheWholeSourceHash(): void {
		[$before, , , ] = FrozenPathFixture::trees(); $sites = json_decode($before[FrozenPathSites::PATH], true)['sites']; $site = $sites[3];
		foreach (['use function Evil\\file_get_contents; ', 'function file_get_contents($p) { return $p; } '] as $override) {
			$tree = $before; $code = str_replace('class CheckoutWriteHandler', $override . 'class CheckoutWriteHandler', $tree[$site['map_path']]); $tree[$site['map_path']] = $code;
			try { FrozenPathCall::locate($site, $code, new SourceTreeIndex($tree)); self::fail('Override passed'); }
			catch (RuntimeException $error) { self::assertStringContainsString('not the proved builtin', $error->getMessage()); }
		}
		$code = str_replace('string $body)', "string \$body = 'x')", $before[$site['map_path']]);
		try { FrozenPathCall::locate($site, $code, new SourceTreeIndex($before)); self::fail('Default passed'); }
		catch (RuntimeException $error) { self::assertStringContainsString('required string', $error->getMessage()); }
	}
	public function testInactiveTicketDoesNotReadEvenAnInvalidManifest(): void {
		[$before, $after, $map, , $certificates] = FrozenPathFixture::trees();
		$after[FrozenPathSites::PATH] = '{';
		$problems = (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-OTHER');
		self::assertNotEmpty($problems);
		self::assertStringNotContainsString('Frozen manifest', implode("\n", $problems));
		self::assertSame(4, count(array_filter($problems, static fn (string $p): bool => str_contains($p, 'whose value depends'))));
	}

	/** @return iterable<string,array{string}> Changes to any caller invalidate even two equally rewritten trees. */
	public static function callerMutants(): iterable {
		foreach (['added caller', 'removed caller', 'indirect caller', 'closure changed', 'dynamic dispatch', 'recomputed JSON'] as $kind) { yield $kind => [$kind]; }
	}

	#[DataProvider('callerMutants')]
	public function testCompleteUniverseCannotLoseAnyCaller(string $kind): void {
		[$before, $after, $map, , $certificates] = FrozenPathFixture::trees();
		$path = implode('/', ['lib', 'Proof', 'Caller']) . '.php';
		if ($kind === 'removed caller') { unset($before[$path], $after[$path]); }
		elseif ($kind === 'added caller') { $before['lib/Extra.php'] = $after['lib/Extra.php'] = '<?php class Extra {}'; }
		else {
			$replacement = match ($kind) { 'indirect caller' => 'return $this->forward($path);', 'closure changed' => 'return (fn () => $path)();', 'dynamic dispatch' => 'return $this->$method($path);', default => "return 'changed';" };
			$before[$path] = $after[$path] = str_replace('return $path;', $replacement, $before[$path]);
			if ($kind === 'recomputed JSON') {
				$data = json_decode($before[FrozenPathSites::PATH], true); $data['production'][$path] = 'refreshed';
				$before[FrozenPathSites::PATH] = $after[FrozenPathSites::PATH] = json_encode($data, JSON_THROW_ON_ERROR);
			}
		}
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21'));
		self::assertStringContainsString(FrozenPathSites::PATH . ':', $problems);
		self::assertStringContainsString('path not proved', $problems);
	}

	public function testSecondCallOnSameLineCannotConsumeTheFirstAuthorization(): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees();
		foreach ([$before, $after] as $side => $files) {
			foreach ($files as $path => $code) { if (str_contains($code, 'return dirname($path);')) { $files[$path] = str_replace('return dirname($path);', 'return dirname($path) . dirname($other);', $code); } }
			if ($side === 0) { $before = $files; } else { $after = $files; }
		}
		// Deliberately grant a newly reviewed seal here to isolate occurrence accounting from the independent seal check.
		$renames = []; foreach ($map->entries as $e) { if ($e['target'] !== 'stays') { $renames[$e['fqcn']] = $e['target']; } }
		$index = new SourceTreeIndex($before); $data = json_decode($before[FrozenPathSites::PATH], true);
		$data['production'] = FrozenProduction::inventory($before, $index, $renames, $pairs);
		foreach ($data['sites'] as &$site) {
			$source = (new TokenNormalizer($index))->normalize($before[$site['map_path']], $renames, $site['map_path'], $pairs);
			$site['occurrence'] = \OCA\Mcp\Scripts\Dev\FrozenPathOccurrence::at($source, $source->located[0][2]);
		} unset($site);
		$before[FrozenPathSites::PATH] = $after[FrozenPathSites::PATH] = json_encode($data, JSON_THROW_ON_ERROR);
		$certificates[0]['seal'] = hash('sha256', $before[FrozenPathSites::PATH]);
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21'));
		self::assertStringContainsString('path not proved', $problems);
	}

}
