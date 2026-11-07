<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\{FqcnMap, SourceTreeIndex, FrozenProduction, FrozenPathOccurrence, FrozenPathSites, TokenNormalizer};

/** Small certificate-specific trees keep gate mutations independent of the production manifest generator. */
final class FrozenPathFixture {
	/**
	 * @param string $ticket Reviewed transition.
	 * @return array{array<string,string>,array<string,string>,FqcnMap,array<string,string>,array} Old/new trees, map and real pairs.
	 */
	public static function trees(string $ticket = 'RE-21'): array {
		if ($ticket === 'RE-23') { return self::re23(); }
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
		if ($ticket === 'RE-24') {
			$before = []; $after = []; $pairs = []; $entries = []; $specs = [];
			foreach ([
				['Checkout/CheckoutToken', 'CheckoutToken'], ['Checkout/CheckoutTokenStore', 'CheckoutTokenStore'],
				['Modules/Files/CheckoutService', 'CheckoutService'], ['Modules/Files/Handler/CheckoutFileHandler', 'CheckoutFileHandler'],
				['Modules/Files/Handler/CheckoutWriteHandler', 'CheckoutWriter'],
			] as [$relative, $target]) {
				$old = 'lib/' . $relative . '.php'; $new = 'lib/Modules/Files/Checkout/' . $target . '.php';
				$from = $prefix . '\\' . str_replace('/', '\\', $relative); $to = $prefix . '\\Modules\\Files\\Checkout\\' . $target;
				$split = strrpos($from, '\\'); $namespace = substr($from, 0, $split); $short = substr($from, $split + 1);
				$body = $target === 'CheckoutWriter' ? 'public function write(string $body): string { return file_get_contents($body); }' : '';
				$before[$old] = "<?php namespace $namespace; use $guard; class $short { $body }";
				$after[$new] = "<?php namespace $prefix\\Modules\\Files\\Checkout; use $guard; class $target { $body }"; $pairs[$old] = $new;
				$entries[] = ['path' => $old, 'fqcn' => $from, 'target' => $to, 'owner' => 'Modules\\Files', 'ticket' => $ticket];
				if ($target === 'CheckoutWriter') { $specs[] = [$old, 'write', '$body', '$body', 'file_get_contents', ['kind' => 'parameter']]; }
			}
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
		$data = ['version' => 2, 'ticket' => $ticket, 'base_commit' => str_repeat('1', 40), 'base_tree' => str_repeat('2', 40),
			'production' => FrozenProduction::inventory($before, $index, $renames, $pairs), 'sites' => $sites, 'external_evidence' => []];
		$before[FrozenPathSites::PATH] = json_encode($data, JSON_THROW_ON_ERROR);
		$after[FrozenPathSites::PATH] = $before[FrozenPathSites::PATH];
		$trusted = ['ticket' => $ticket, 'pairs_sha256' => FrozenProduction::pairsHash($pairs), 'path' => FrozenPathSites::PATH,
			'seal' => hash('sha256', $before[FrozenPathSites::PATH]), 'sites' => count($sites), 'base_commit' => $data['base_commit'], 'base_tree' => $data['base_tree']];
		return [$before, $after, $map, $pairs, [$trusted]];
	}

	/** @return array{array<string,string>,array<string,string>,FqcnMap,array<string,string>,array} The exact RE-23 pair/class shape with its two sites and both MoveHandler callers. */
	private static function re23(): array {
		$prefix = 'OCA\\Mcp\\Modules\\Files\\'; $before = []; $after = []; $pairs = []; $entries = []; $renames = []; $sites = [];
		$guard = 'OCA\\Mcp\\Platform\\FileAccess\\PathGuard';
		foreach (['Batch', 'BatchStore', 'CopyMoveContext', 'Inspection', 'MoveBatchPlanner', 'MoveConflict', 'MovePlanner', 'ReorganizationLimits',
			'FileDestinationPolicy', 'FileTypePreflight', 'ListedTreeCopy', 'Reorganization', 'FolderExposureGate', 'Handler/CopyHandler',
			'Handler/MoveHandler', 'Handler/MoveBatchHandler', 'Handler/UndoBatchHandler', 'MoveReport', 'VersionTools', 'VersionRestore', 'VersionStream', 'Reading/ReadVersionHandler'] as $relative) {
			$short = basename($relative); $integration = in_array($short, ['MoveReport', 'VersionTools', 'VersionRestore', 'VersionStream', 'ReadVersionHandler'], true);
			$old = 'lib/Modules/Files/' . $relative . '.php'; $new = 'lib/Modules/Files/' . ($integration ? 'Integration' : 'Reorganization') . '/' . $short . '.php';
			$from = $prefix . str_replace('/', '\\', $relative); $to = $prefix . ($integration ? 'Integration' : 'Reorganization') . '\\' . $short;
			$body = match ($short) {
				'Reorganization' => "public function parentExists(string \$path): string { return dirname(PathGuard::normalize(\$path,'moves')); }",
				'FolderExposureGate' => 'public static function assertMove(string $to): string { return dirname(PathGuard::normalize($to)); }',
				'MoveHandler' => 'public function handle(string $to): void { FolderExposureGate::assertMove($to); } public function planMove(string $to): void { FolderExposureGate::assertMove($to); }',
				default => '',
			};
			$namespace = substr($from, 0, (int)strrpos($from, '\\')); $targetNamespace = substr($to, 0, (int)strrpos($to, '\\'));
			$imports = 'use ' . $guard . '; use ' . $prefix . 'FolderExposureGate;';
			$before[$old] = "<?php namespace $namespace; $imports class $short { $body }";
			$after[$new] = str_replace(['namespace ' . $namespace . ';', $prefix . 'FolderExposureGate;'], ['namespace ' . $targetNamespace . ';', $prefix . 'Reorganization\\FolderExposureGate;'], $before[$old]);
			$entry = ['path' => $old, 'fqcn' => $from, 'target' => $to, 'owner' => 'Modules\\Files', 'ticket' => 'RE-23'];
			if ($short === 'MovePlanner') {
				$before[$old] .= ' class MovePlan {}'; $after[$new] .= ' class MovePlan {}';
				$entry['companions'] = [$prefix . 'MovePlan', $prefix . 'Reorganization\\MovePlan']; $renames[$prefix . 'MovePlan'] = $prefix . 'Reorganization\\MovePlan';
			}
			$pairs[$old] = $new; $renames[$from] = $to; $entries[] = $entry;
		}
		$before['lib/Proof/Caller.php'] = $after['lib/Proof/Caller.php'] = '<?php namespace OCA\\Mcp\\Proof; class Caller { public function input(string $path): string { return $path; } }';
		$entries[] = ['path' => 'lib/Proof/Caller.php', 'fqcn' => 'OCA\\Mcp\\Proof\\Caller', 'target' => 'stays', 'owner' => 'Platform', 'ticket' => null];
		$index = new SourceTreeIndex($before); $normalizer = new TokenNormalizer($index);
		foreach ([['Reorganization', 'parentExists', '$path', 'moves'], ['FolderExposureGate', 'assertMove', '$to', null]] as [$short, $method, $parameter, $label]) {
			$old = 'lib/Modules/Files/' . $short . '.php'; $source = $normalizer->normalize($before[$old], $renames, $old, $pairs);
			$sites[] = ['map_path' => $old, 'target' => $renames[$prefix . $short], 'method' => $method, 'callable' => 'dirname', 'ordinal' => 1,
				'parameter' => $parameter, 'transform' => ['kind' => 'path-guard', 'class' => $guard, 'label' => $label],
				'occurrence' => FrozenPathOccurrence::at($source, $source->located[0][2])];
		}
		$data = ['version' => 2, 'ticket' => 'RE-23', 'base_commit' => str_repeat('3', 40), 'base_tree' => str_repeat('4', 40),
			'production' => FrozenProduction::inventory($before, $index, $renames, $pairs), 'sites' => $sites, 'external_evidence' => []];
		$path = 'scripts/dev/frozen-path-sites-re23.json'; $before[$path] = $after[$path] = json_encode($data, JSON_THROW_ON_ERROR);
		$trusted = ['ticket' => 'RE-23', 'pairs_sha256' => FrozenProduction::pairsHash($pairs), 'path' => $path, 'seal' => hash('sha256', $before[$path]),
			'sites' => 2, 'base_commit' => $data['base_commit'], 'base_tree' => $data['base_tree']];
		return [$before, $after, new FqcnMap($entries, 'OCA\\Mcp\\', 'lib/'), $pairs, [$trusted]];
	}
}
