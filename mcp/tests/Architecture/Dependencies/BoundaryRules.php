<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture\Dependencies;

use OCA\Mcp\Tests\Architecture\Inventory\AppTree;

/** The contract matrix, shared by the live tree and fabricated violations. */
final class BoundaryRules {
	/** @param AppTree $tree Owner/map index. @param array<string,string>|null $files Override for fixtures. @return list<array<string,mixed>> Violations. */
	public static function violations(AppTree $tree, ?array $files = null): array {
		$found = [];
		foreach ($files ?? $tree->files as $path => $source) {
			if (!str_ends_with($path, '.php')) { continue; }
			$class = 'OCA\\Mcp\\' . str_replace('/', '\\', substr($path, 4, -4));
			$owner = $tree->owner($class);
			foreach (ClassReferences::read($source) as $edge) {
				$target = $edge['target'];
				$rule = null;
				if ($edge['kind'] === 'dynamic-prefix') {
					$resolved = DynamicReferences::resolve($path, $source, $edge);
					foreach ($resolved as $name) {
						if (!str_starts_with($path, 'lib/Modules/Forms/Integration/')) { $found[] = ['rule' => 'native-app', 'source' => $path, ...$edge, 'target' => $name, 'owner' => $owner]; }
					}
					continue;
				} elseif (str_starts_with($target, 'OCA\\') && !str_starts_with($target, 'OCA\\Mcp\\')) {
					if (!str_starts_with($path, 'lib/' . str_replace('\\', '/', $owner) . '/Integration/') || !str_starts_with($owner, 'Modules\\')) { $rule = 'native-app'; }
				} elseif (str_starts_with($target, 'OCA\\Mcp\\')) {
					// A namespace alias import is resolved again at each class use; it is not itself a class.
					if ($edge['kind'] === 'import' && !isset($tree->names[$target]) && array_filter(array_keys($tree->names), static fn ($name) => str_starts_with($name, $target . '\\'))) { continue; }
					$to = isset($tree->names[$target]) ? $tree->owner($target) : 'unmapped';
					if ($to === 'unmapped') { $rule = 'unresolved-class'; }
					elseif (str_starts_with($owner, 'Modules\\') && str_starts_with($to, 'Modules\\') && $owner !== $to) { $rule = 'module-isolation'; }
					elseif (!self::allowed($owner, $to, $class, $target, $tree->names[$class]['fqcn'] ?? null)) { $rule = 'layering'; }
				} elseif (str_contains($target, '\\') && !preg_match('/^(OCP|OC|Psr|Sabre|Smalot|Symfony)\\\\/', $target)) {
					$rule = 'external-prefix';
				}
				if ($rule !== null) { $found[] = ['rule' => $rule, 'source' => $path, ...$edge, 'owner' => $owner]; }
			}
		}
		$unique = [];
		foreach ($found as $edge) { $unique[self::key($edge)] = $edge; }
		ksort($unique);
		return array_values($unique);
	}

	/** @param string $from Source owner. @param string $to Target owner. @param string $class Source FQCN. @param string $target Target FQCN. @return bool Contract permission. */
	public static function allowed(string $from, string $to, string $class, string $target, ?string $historical = null): bool {
		if (str_starts_with($target, 'OCA\\Mcp\\L10n\\') || str_starts_with($target, 'OCA\\Mcp\\Mcp\\Tools\\Results\\') || $target === 'OCA\\Mcp\\Mcp\\Tools\\Validation\\ArgumentValidationException') { return true; }
		if ($from === 'Access' && $to === 'Storage') {
			return $target === 'OCA\\Mcp\\OAuth\\Storage\\OAuthStore' && ($historical === implode('\\', ['OCA', 'Mcp', 'Service', 'Policy', 'PolicyEventProcessor']) || str_starts_with($class, 'OCA\\Mcp\\Access\\Policy\\Credentials\\'));
		}
		if ($from === $to) { return true; }
		$matrix = ['Platform' => [], 'Storage' => ['Platform', 'Db'], 'Access' => ['Platform', 'Db'], 'OAuth' => ['Access', 'Storage', 'Platform', 'Db'], 'Mcp' => ['OAuth', 'Storage', 'Access', 'Platform']];
		if (str_starts_with($from, 'Modules\\')) { return in_array($to, ['Mcp', 'Access', 'Platform', 'Storage'], true); }
		return !isset($matrix[$from]) || in_array($to, $matrix[$from], true);
	}

	/** @param array<string,mixed> $edge Rule/source/target row. @return string Exact exception identity, never a prefix. */
	public static function key(array $edge): string { return $edge['rule'] . '|' . $edge['source'] . '|' . $edge['target'] . ($edge['rule'] === 'external-prefix' ? ':' . $edge['line'] : ''); }
}
