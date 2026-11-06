<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\{FqcnMap, SourceTreeIndex, FrozenProduction, FrozenPathOccurrence, FrozenPathSites, TokenNormalizer};

/** Small four-site trees keep gate mutations independent of the production manifest generator. */
final class FrozenPathFixture {
	/** @return array{array<string,string>,array<string,string>,FqcnMap,array<string,string>,array} Old/new trees, map and real pairs. */
	public static function trees(): array {
		$prefix = implode('\\', ['OCA', 'Mcp']); $entries = []; $before = []; $after = []; $pairs = []; $specs = [];
		$oldPrefix = $prefix . '\\Tools\\Files'; $newPrefix = $prefix . '\\Modules\\Files';
		$guard = $prefix . '\\Platform\\FileAccess\\PathGuard';
		foreach ([
			['FileBackupCopy', 'copy', '$path', '$path', 'dirname', ['kind' => 'parameter']],
			['FolderExposureGate', 'assertMove', '$to', 'PathGuard::normalize($to)', 'dirname', ['kind' => 'path-guard', 'class' => $guard, 'label' => null]],
			['Reorganization', 'parentExists', '$path', "PathGuard::normalize(\$path,'moves')", 'dirname', ['kind' => 'path-guard', 'class' => $guard, 'label' => 'moves']],
			['Handler/CheckoutWriteHandler', 'write', '$body', '$body', 'file_get_contents', ['kind' => 'parameter']],
		] as [$relative, $method, $parameter, $operand, $call, $transform]) {
			$old = implode('/', ['lib', 'Tools', 'Files', $relative]) . '.php'; $new = str_replace('/Tools/', '/Modules/', $old);
			$class = str_replace('/', '\\', $relative); $at = strrpos($class, '\\');
			$namespace = $oldPrefix . ($at === false ? '' : '\\' . substr($class, 0, $at)); $short = $at === false ? $class : substr($class, $at + 1);
			$before[$old] = "<?php namespace $namespace; use $guard; class $short { public function $method(string $parameter): string { return $call($operand); } }";
			$after[$new] = str_replace($oldPrefix, $newPrefix, $before[$old]); $pairs[$old] = $new;
			$entries[] = ['path' => $old, 'fqcn' => $oldPrefix . '\\' . $class, 'target' => $newPrefix . '\\' . $class, 'owner' => 'Modules\\Files', 'ticket' => 'RE-21'];
			$specs[] = [$old, $method, $parameter, $operand, $call, $transform];
		}
		$origin = implode('/', ['lib', 'Proof', 'Caller']) . '.php';
		$before[$origin] = "<?php namespace $prefix\\Proof; class Caller { public function input(string \$path): string { return \$path; } }";
		$after[$origin] = $before[$origin];
		$entries[] = ['path' => $origin, 'fqcn' => $prefix . '\\Proof\\Caller', 'target' => 'stays', 'owner' => 'Platform', 'ticket' => null];
		$map = new FqcnMap($entries, $prefix . '\\', 'lib/'); $sites = []; $renames = [];
		foreach ($entries as $entry) { if ($entry['target'] !== 'stays') { $renames[$entry['fqcn']] = $entry['target']; } }
		$index = new SourceTreeIndex($before); $normalizer = new TokenNormalizer($index);
		foreach ($specs as [$old, $method, $parameter, $operand, $call, $transform]) {
			$source = $normalizer->normalize($before[$old], $renames, $old, $pairs);
			$sites[] = ['map_path' => $old, 'target' => $renames[$index->declaredIn($old)[0]], 'method' => $method, 'callable' => $call, 'ordinal' => 1,
				'parameter' => $parameter, 'transform' => $transform, 'occurrence' => FrozenPathOccurrence::at($source, $source->located[0][2])];
		}
		$data = ['version' => 2, 'ticket' => 'RE-21', 'base_commit' => str_repeat('1', 40), 'base_tree' => str_repeat('2', 40),
			'production' => FrozenProduction::inventory($before, $index, $renames, $pairs), 'sites' => $sites, 'external_evidence' => []];
		$before[FrozenPathSites::PATH] = json_encode($data, JSON_THROW_ON_ERROR);
		$after[FrozenPathSites::PATH] = $before[FrozenPathSites::PATH];
		$trusted = ['ticket' => 'RE-21', 'pairs_sha256' => FrozenProduction::pairsHash($pairs), 'path' => FrozenPathSites::PATH,
			'seal' => hash('sha256', $before[FrozenPathSites::PATH]), 'sites' => 4, 'base_commit' => $data['base_commit'], 'base_tree' => $data['base_tree']];
		return [$before, $after, $map, $pairs, [$trusted]];
	}
}
