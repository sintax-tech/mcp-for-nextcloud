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
		foreach (['RE-21', 'RE-24'] as $ticket) {
			[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees($ticket);
			self::assertSame([], (new MoveEquivalence($before, $after, $map, $certificates))->check($ticket));
			unset($before[FrozenPathSites::PATH], $after[FrozenPathSites::PATH]);
			$problems = (new MoveEquivalence($before, $after, $map, $certificates))->check($ticket);
			self::assertCount(($ticket === 'RE-24' ? 1 : 4) + 1, $problems);
			self::assertSame($ticket === 'RE-24' ? 1 : 4, count(array_filter($problems, static fn (string $p): bool => str_contains($p, 'path not proved'))));
		}
	}

	/** @return iterable<string,array{0:string,1:string,2?:string}> Equal mutations on both sides must still invalidate the frozen source. */
	public static function sourceMutants(): iterable {
		$mutants['different parameter'] = ['$body', '$other'];
		$mutants['source file assignment'] = ['return file_get_contents', '$body = __FILE__; return file_get_contents'];
		$mutants['source directory assignment'] = ['return file_get_contents', '$body = __DIR__; return file_get_contents'];
		$mutants['parameter default'] = ['string $body)', "string \$body = 'relative')"];
		$mutants['extra include-path argument'] = ['file_get_contents($body)', 'file_get_contents($body,true)'];
		$mutants['named include-path argument'] = ['file_get_contents($body)', 'file_get_contents($body,use_include_path:true)'];
		$mutants['another method'] = ['function write(', 'function other('];
		$mutants['unlisted second call'] = ['return file_get_contents', 'file_get_contents($body); return file_get_contents'];
		$mutants['function alias'] = ['class CheckoutWriteHandler', 'use function Evil\\file_get_contents; class CheckoutWriteHandler'];
		$mutants['namespace override'] = ['class CheckoutWriteHandler', 'function file_get_contents($p) { return $p; } class CheckoutWriteHandler'];
		$mutants['different PathGuard alias'] = ['Platform\\FileAccess\\PathGuard;', 'Other\\PathGuard;'];
		foreach (['RE-21', 'RE-24'] as $ticket) { foreach ($mutants as $label => $args) { if ($ticket === 'RE-24' && $label === 'different PathGuard alias') { continue; } yield $ticket . '/' . $label => [...$args, $ticket]; } }

		foreach ([
			['path reassignment', "return dirname(PathGuard::normalize(\$path,'moves'))", "\$path = __FILE__; return dirname(PathGuard::normalize(\$path,'moves'))"],
			['destination reassignment', 'return dirname(PathGuard::normalize($to))', '$to = __DIR__; return dirname(PathGuard::normalize($to))'],
			['parameter default', 'string $path)', "string \$path = 'relative')"],
			['normalization label', "normalize(\$path,'moves')", "normalize(\$path,'other')"],
			['extra argument', 'dirname(PathGuard::normalize($to))', 'dirname(PathGuard::normalize($to),2)'],
			['second dirname', 'return dirname(PathGuard::normalize($to));', 'return dirname(PathGuard::normalize($to)) . dirname($to);'],
			['another method', 'function parentExists(', 'function other('],
			['builtin alias', 'class Reorganization', 'use function Evil\\dirname; class Reorganization'],
			['builtin override', 'class Reorganization', 'function dirname($p) { return $p; } class Reorganization'],
			['PathGuard alias', 'Platform\\FileAccess\\PathGuard;', 'Other\\PathGuard;'],
		] as [$label, $find, $replace]) { yield 'RE-23 ' . $label => [$find, $replace, 'RE-23']; }
	}
	/**
	 * @param string $find Original expression.
	 * @param string $replace Unreviewed expression.
	 * @param string $ticket Reviewed transition.
	 * @return void
	 */
	#[DataProvider('sourceMutants')]
	public function testEqualButUnreviewedSourcesAreRejected(string $find, string $replace, string $ticket): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees($ticket); $certificatePath = $certificates[0]['path']; $changed = 0;
		foreach ([$before, $after] as $side => $tree) {
			foreach ($tree as $path => $code) {
				if (str_ends_with($path, '.php') && str_contains($code, $ticket === 'RE-24' && $side === 1 ? str_replace('class CheckoutWriteHandler', 'class CheckoutWriter', $find) : $find)) {
					$sideFind = $ticket === 'RE-24' && $side === 1 ? str_replace('class CheckoutWriteHandler', 'class CheckoutWriter', $find) : $find;
					$sideReplace = $ticket === 'RE-24' && $side === 1 ? str_replace('class CheckoutWriteHandler', 'class CheckoutWriter', $replace) : $replace;
					$tree[$path] = str_replace($sideFind, $sideReplace, $code); $changed++;
				}
			}
			if ($side === 0) { $before = $tree; } else { $after = $tree; }
		}
		self::assertGreaterThanOrEqual(2, $changed);
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check($ticket));
		self::assertStringContainsString($certificatePath . ':', $problems);
		self::assertStringContainsString('path not proved', $problems);
	}

	public function testCallerChangesInvalidateEvenIdenticalInputTrees(): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees();
		foreach ($before as $path => $code) {
			if (str_contains($code, 'class Caller')) { $before[$path] = $after[$path] = str_replace('return $path;', "return 'changed';", $code); }
		}
		self::assertStringContainsString('caller/origin changed', implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-21')));
	}

	/** @return iterable<string,array{0:string,1?:string}> Manifest failures, including entries that cannot be consumed. */
	public static function manifestMutants(): iterable {
		foreach (['one-sided edit', 'missing before', 'missing after', 'malformed', 'duplicate', 'excess', 'unknown identity', 'unknown field', 'missing provenance', 'wrong ordinal', 'changed operand hash'] as $kind) { foreach (['RE-21', 'RE-24'] as $ticket) { yield $ticket . '/' . $kind => [$kind, $ticket]; } }

		foreach (['one-sided edit', 'missing before', 'missing after', 'malformed', 'duplicate', 'excess', 'unknown identity', 'wrong ordinal', 'changed operand hash'] as $kind) { yield 'RE-23 ' . $kind => [$kind, 'RE-23']; }
	}
	/**
	 * @param string $kind Manifest mutation.
	 * @param string $ticket Reviewed transition.
	 * @return void
	 */
	#[DataProvider('manifestMutants')]
	public function testInvalidOrUnconsumableManifestCannotGrantAProof(string $kind, string $ticket = 'RE-21'): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees($ticket); $certificatePath = $certificates[0]['path']; $data = json_decode($before[$certificatePath], true);
		switch ($kind) {
			case 'one-sided edit': $after[$certificatePath] .= "\n"; break;
			case 'missing before': unset($before[$certificatePath]); break;
			case 'missing after': unset($after[$certificatePath]); break;
			case 'malformed': $before[$certificatePath] = $after[$certificatePath] = '{'; break;
			default:
				switch ($kind) {
					case 'duplicate': if ($ticket === 'RE-23') { $data['sites'][count($data['sites']) - 1] = $data['sites'][0]; } else { $data['sites'][] = $data['sites'][0]; } break;
					case 'excess': $data['sites'][] = $data['sites'][0]; break;
					case 'unknown identity': $data['sites'][0]['map_path'] = 'lib/Unknown.php'; break;
					case 'unknown field': $data['sites'][0]['allow_any'] = true; break;
					case 'missing provenance': $data['production'] = []; break;
					case 'wrong ordinal': $data['sites'][0]['ordinal'] = 2; break;
					case 'changed operand hash': $data['sites'][0]['occurrence']['operand_sha256'] = str_repeat('0', 64); break;
				}
				$before[$certificatePath] = $after[$certificatePath] = json_encode($data, JSON_THROW_ON_ERROR);
		}
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check($ticket));
		self::assertStringContainsString($certificatePath . ':', $problems);
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
	/**
	 * @param string $kind Inactive transition.
	 * @return void
	 */
	#[DataProvider('inactiveRanges')]
	public function testInactiveTicketDoesNotReadEvenAnInvalidManifest(string $kind = 'RE-21 wrong ticket'): void {
		if (str_starts_with($kind, 'RE-23')) {
			[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees('RE-23'); $path = $certificates[0]['path'];
			$after[$path] = '{'; $ticket = 'RE-23';
			if ($kind === 'RE-23 wrong RE-21') { $ticket = 'RE-21'; }
			elseif ($kind === 'RE-23 wrong RE-24') { $ticket = 'RE-24'; }
			elseif ($kind === 'RE-23 missing pair') { array_pop($pairs); }
			elseif ($kind === 'RE-23 extra pair') { $pairs['lib/Extra.php'] = 'lib/Moved/Extra.php'; }
			elseif ($kind === 'RE-23 duplicate destination') { $pairs[array_key_first($pairs)] = end($pairs); }
			$proof = new FrozenPathSites($before, $after, $pairs, [], $map, new SourceTreeIndex($before), new SourceTreeIndex($after), $ticket, $certificates);
			self::assertSame([], $proof->problems(), 'Inactive data must not be read even when malformed');
			$descriptor = $certificates[0]; $descriptor['ticket'] = 'RE-OTHER';
			$problems = (new MoveEquivalence($before, $after, $map, [$descriptor]))->check('RE-23');
			self::assertSame(2, count(array_filter($problems, static fn (string $p): bool => str_contains($p, 'whose value depends'))));
			return;
		}
		[$before, $after, $map, , $certificates] = FrozenPathFixture::trees();
		$after[FrozenPathSites::PATH] = '{';
		$problems = (new MoveEquivalence($before, $after, $map, $certificates))->check('RE-OTHER');
		self::assertNotEmpty($problems);
		self::assertStringNotContainsString('Frozen manifest', implode("\n", $problems));
		self::assertSame(4, count(array_filter($problems, static fn (string $p): bool => str_contains($p, 'whose value depends'))));
	}

	/** @return iterable<string,array{0:string,1?:string}> Changes to any caller invalidate even two equally rewritten trees. */
	public static function callerMutants(): iterable {
		foreach (['added caller', 'removed caller', 'indirect caller', 'closure changed', 'dynamic dispatch', 'recomputed JSON'] as $kind) { foreach (['RE-21', 'RE-24'] as $ticket) { yield $ticket . '/' . $kind => [$kind, $ticket]; } }

		foreach (['added caller', 'removed caller', 'indirect caller', 'closure changed', 'dynamic dispatch', 'recomputed JSON', 'move handler effect', 'move handler plan'] as $kind) { yield 'RE-23 ' . $kind => [$kind, 'RE-23']; }
	}
	/**
	 * @param string $kind Caller mutation.
	 * @param string $ticket Reviewed transition.
	 * @return void
	 */
	#[DataProvider('callerMutants')]
	public function testCompleteUniverseCannotLoseAnyCaller(string $kind, string $ticket = 'RE-21'): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees($ticket); $certificatePath = $certificates[0]['path'];
		$path = implode('/', ['lib', 'Proof', 'Caller']) . '.php';
		if (str_starts_with($kind, 'move handler ')) {
			$path = 'lib/Modules/Files/Handler/MoveHandler.php'; $target = $pairs[$path];
			$method = $kind === 'move handler effect' ? 'handle' : 'planMove';
			foreach ([0 => $path, 1 => $target] as $side => $physical) {
				$files = $side === 0 ? $before : $after; $files[$physical] = str_replace($method . '(string $to): void { FolderExposureGate::assertMove($to);', $method . '(string $to): void { FolderExposureGate::assertMove("changed");', $files[$physical]);
				if ($side === 0) { $before = $files; } else { $after = $files; }
			}
		} elseif ($kind === 'removed caller') { unset($before[$path], $after[$path]); }
		elseif ($kind === 'added caller') { $before['lib/Extra.php'] = $after['lib/Extra.php'] = '<?php class Extra {}'; }
		else {
			$replacement = match ($kind) { 'indirect caller' => 'return $this->forward($path);', 'closure changed' => 'return (fn () => $path)();', 'dynamic dispatch' => 'return $this->$method($path);', default => "return 'changed';" };
			$before[$path] = $after[$path] = str_replace('return $path;', $replacement, $before[$path]);
			if ($kind === 'recomputed JSON') {
				$data = json_decode($before[$certificatePath], true); $names = []; foreach ($map->entries as $entry) { if ($entry['target'] !== 'stays') { $names[$entry['fqcn']] = $entry['target']; } }
				if ($ticket === 'RE-23') { $names['OCA\\Mcp\\Modules\\Files\\MovePlan'] = 'OCA\\Mcp\\Modules\\Files\\Reorganization\\MovePlan'; }
				if ($ticket === 'RE-23') { $data['production'] = FrozenProduction::inventory($before, new SourceTreeIndex($before), $names, $pairs); } else { $data['production'][$path] = 'refreshed'; }
				$before[$certificatePath] = $after[$certificatePath] = json_encode($data, JSON_THROW_ON_ERROR);
			}
		}
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check($ticket));
		self::assertStringContainsString($certificatePath . ':', $problems);
		self::assertStringContainsString('path not proved', $problems);
	}

	/**
	 * @param string $ticket Reviewed transition.
	 * @return void
	 */
	#[DataProvider('certifiedTickets')]
	public function testSecondCallOnSameLineCannotConsumeTheFirstAuthorization(string $ticket = 'RE-21'): void {
		[$before, $after, $map, $pairs, $certificates] = FrozenPathFixture::trees($ticket); $certificatePath = $certificates[0]['path'];
		self::assertSame([], (new MoveEquivalence($before, $after, $map, $certificates))->check($ticket));
		if ($ticket === 'RE-23') {
			self::assertCount(22, $pairs);
			$registered = array_values(array_filter(\OCA\Mcp\Scripts\Dev\FrozenPathCertificates::all(), static fn (array $d): bool => $d['ticket'] === 'RE-23'))[0];
			self::assertSame($registered['pairs_sha256'], FrozenProduction::pairsHash($pairs));
			self::assertCount(23, array_merge(...array_map((new SourceTreeIndex($before))->declaredIn(...), array_keys($pairs))));
		}
		foreach ([$before, $after] as $side => $files) {
			foreach ($files as $path => $code) { if ($ticket === 'RE-24' && str_contains($code, 'return file_get_contents($body);')) { $files[$path] = str_replace('return file_get_contents($body);', 'return file_get_contents($body) . file_get_contents($other);', $code); } elseif (str_contains($code, 'return dirname(')) { $files[$path] = preg_replace('~return (dirname\(.+\));~', 'return $1 . dirname($other);', $code); } }
			if ($side === 0) { $before = $files; } else { $after = $files; }
		}
		// Deliberately grant a newly reviewed seal here to isolate occurrence accounting from the independent seal check.
		$renames = []; foreach ($map->entries as $e) { if ($e['target'] !== 'stays') { $renames[$e['fqcn']] = $e['target']; } }
		if ($ticket === 'RE-23') { $renames['OCA\\Mcp\\Modules\\Files\\MovePlan'] = 'OCA\\Mcp\\Modules\\Files\\Reorganization\\MovePlan'; }
		$index = new SourceTreeIndex($before); $data = json_decode($before[$certificatePath], true);
		$data['production'] = FrozenProduction::inventory($before, $index, $renames, $pairs);
		foreach ($data['sites'] as &$site) {
			$source = (new TokenNormalizer($index))->normalize($before[$site['map_path']], $renames, $site['map_path'], $pairs);
			$site['occurrence'] = \OCA\Mcp\Scripts\Dev\FrozenPathOccurrence::at($source, $source->located[0][2]);
		} unset($site);
		$before[$certificatePath] = $after[$certificatePath] = json_encode($data, JSON_THROW_ON_ERROR);
		$certificates[0]['seal'] = hash('sha256', $before[$certificatePath]);
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map, $certificates))->check($ticket));
		self::assertStringContainsString('path not proved', $problems);
	}

	/** @return iterable<string,array{string}> Existing mutation controls run with both reviewed transition shapes. */
	public static function certifiedTickets(): iterable {
		yield 'RE-21' => ['RE-21'];
		yield 'RE-23' => ['RE-23'];
		yield 'RE-24' => ['RE-24'];
	}

	/** @return iterable<string,array{string}> Activation is exact and precedes reading any manifest. */
	public static function inactiveRanges(): iterable {
		foreach (['RE-21 wrong ticket', 'RE-23 wrong RE-21', 'RE-23 wrong RE-24', 'RE-23 missing pair', 'RE-23 extra pair', 'RE-23 duplicate destination'] as $kind) { yield $kind => [$kind]; }
	}
}
