<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Dependencies;

use OCA\Mcp\Access\Grants\GrantCatalog;
use OCA\Mcp\Mcp\Tools\{Tool, WriteTool};
use ReflectionClass;

/** Checks published metadata and the mechanically provable parts of a thin tool. */
final class ToolContracts {
	/** @param list<array<string,mixed>> $definitions Definitions in publication order. @param list<string> $probes Registered capability keys. @return list<string> Invalid contracts. */
	public static function catalog(array $definitions, array $probes, ?array $expectedNames = null): array {
		$errors = []; $seen = [];
		if ($expectedNames !== null && $expectedNames !== array_column($definitions, 'name')) { $errors[] = 'Catalog order or coverage changed'; }
		if ($definitions === []) { $errors[] = 'Empty catalog'; }
		foreach ($definitions as $row) {
			$name = $row['name'] ?? ''; $module = $row['module'] ?? ''; $operation = $row['operation'] ?? '';
			if (isset($seen[$name]) || (!str_starts_with($name, $module . '_') && !($module === 'people' && $name === 'users_search')) || $module === '') { $errors[] = 'Duplicate/wrong module prefix: ' . $name; }
			// The exact published people/users_search name is kept for client compatibility.
			$seen[$name] = true;
			foreach ([$operation, ...($row['grantAnyOf'] ?? [])] as $grant) {
				if (!in_array($grant, GrantCatalog::CATALOG[$module] ?? [], true)) { $errors[] = 'Unknown grant: ' . $module . '.' . $grant; }
			}
			foreach ($row['requires'] ?? [] as $key) { if (!in_array($key, $probes, true)) { $errors[] = 'Missing capability probe: ' . $key; } }
		}
		return $errors;
	}

	/** @param string $source Tool PHP source. @param bool $write Definition is a write. @return list<string> Invalid structure, using the same collector as live boundaries. */
	public static function thin(string $source, bool $write): array {
		$errors = [];
		$tokens = array_values(array_filter(token_get_all($source), static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$classes = 0; $final = false; $interfaces = [];
		foreach ($tokens as $i => $token) {
			if (!is_array($token)) { continue; }
			if ($token[0] === T_CLASS && !(is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON)) {
				$classes++;
				$previous = $i - 1;
				if (is_array($tokens[$previous] ?? null) && $tokens[$previous][0] === T_READONLY) { $previous--; }
				$final = is_array($tokens[$previous] ?? null) && $tokens[$previous][0] === T_FINAL;
			}
		}
		$refs = ClassReferences::read($source);
		$targets = array_column($refs, 'target');
		if ($classes !== 1 || !$final) { $errors[] = 'Tool must be one final class'; }
		if (!in_array(Tool::class, $targets, true) && !in_array(WriteTool::class, $targets, true)) { $errors[] = 'Tool interface missing'; }
		if ($write && !in_array(WriteTool::class, $targets, true)) { $errors[] = 'WriteTool missing'; }
		foreach ($refs as $edge) {
			if (str_starts_with($edge['target'], 'OCP\\') || (str_starts_with($edge['target'], 'OCA\\') && !str_starts_with($edge['target'], 'OCA\\Mcp\\')) || $edge['target'] === 'Psr\\Container\\ContainerInterface') { $errors[] = 'Native/container dependency in thin tool: ' . $edge['target']; }
		}
		preg_match_all('/(?:->|\?->)\s*get\s*\(/', $source, $gets);
		if ($gets[0] !== []) { $errors[] = 'Service lookup in thin tool'; }
		return array_values(array_unique($errors));
	}

	/** @param list<string> $catalog Published classes. @param list<string> $files Actual Tools files. @return list<string> Coverage failures. */
	public static function coverage(array $catalog, array $files): array {
		sort($catalog); sort($files);
		return $catalog === $files ? [] : ['Tool catalog/file coverage differs'];
	}

	/** @param class-string $class Published tool. @return list<string> Reflection and source violations. */
	public static function reflected(string $class): array {
		$r = new ReflectionClass($class);
		$definition = $class::definition();
		$errors = self::thin((string)file_get_contents($r->getFileName()), $definition->operation !== 'read');
		if (!$r->isFinal() || !$r->implementsInterface(Tool::class) || ($definition->operation !== 'read' && !$r->implementsInterface(WriteTool::class))) { $errors[] = 'Invalid reflected tool interface: ' . $class; }
		$errors = [...$errors, ...self::delegation($r, $definition->operation !== 'read')];
		return $errors;
	}
	/**
	 * A read tool has one work collaborator; a write tool has two, the effect and the plan. Each method calls exactly one
	 * of them, and a write's preview never calls the class its execute calls.
	 *
	 * @param ReflectionClass $class Tool reflection. @param bool $write Definition is a write. @return list<string> Delegation violations.
	 */
	public static function delegation(ReflectionClass $class, bool $write): array {
		$errors = [];
		$expected = $write ? 2 : 1;
		if (self::workCount($class) !== $expected) {
			$errors[] = ($write ? 'Write tool must delegate to one effect and one plan dependency: ' : 'Tool must delegate to one work dependency: ') . $class->getName();
		}
		$work = array_map(static fn ($p) => $p->getName(), array_filter($class->getConstructor()?->getParameters() ?? [], static fn ($p) => $p->getType() instanceof \ReflectionNamedType && !$p->getType()->isBuiltin()));
		$lines = file((string)$class->getFileName()) ?: [];
		$used = [];
		foreach ($write ? ['execute', 'preview'] : ['execute'] as $name) {
			if (!$class->hasMethod($name)) { $errors[] = 'Tool method missing: ' . $class->getName() . '::' . $name; continue; }
			$method = $class->getMethod($name);
			$body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
			preg_match_all('/\$this\??->\s*(\w+)/', $body, $matches);
			$used[$name] = array_values(array_unique(array_intersect($matches[1], $work)));
			if (count($used[$name]) !== 1) { $errors[] = 'Tool method must call one work dependency: ' . $class->getName() . '::' . $name; }
		}
		if (isset($used['execute'], $used['preview']) && $used['execute'] === $used['preview']) { $errors[] = 'Plan and effect must be different dependencies: ' . $class->getName(); }
		return $errors;
	}

	/** Every app interface a module or a tool implements as such (all of Mcp except the ports and CapabilityProbe); none needs a binding. */
	private const MODULE_INTERFACES = [Tool::class, WriteTool::class, \OCA\Mcp\Mcp\Tools\ToolModule::class, \OCA\Mcp\Mcp\Tools\PreviewsWrites::class, \OCA\Mcp\Mcp\Tools\RendersPlans::class, \OCA\Mcp\Mcp\Tools\RestrictedModule::class, \OCA\Mcp\Mcp\Guide\ToolGuideNotes::class, \OCA\Mcp\Mcp\Tools\ModuleRegistration::class];

	/**
	 * A catalog module needs a registration exactly when one of its classes implements an app interface declared
	 * outside the module (a port another layer resolves, or a capability probe); otherwise `null` is its registration.
	 *
	 * @param string|null $registration What the module's registration() answers. @param list<class-string> $classes Classes of the module. @param string $namespace Namespace of the module. @return list<string> Registration violations.
	 */
	public static function registration(?string $registration, array $classes, string $namespace): array {
		if ($registration !== null) {
			return is_subclass_of($registration, \OCA\Mcp\Mcp\Tools\ModuleRegistration::class) ? [] : ['Registration is not a ModuleRegistration: ' . $registration];
		}
		$errors = [];
		foreach ($classes as $class) {
			foreach (class_implements($class) ?: [] as $interface) {
				if (str_starts_with($interface, 'OCA\\Mcp\\') && !str_starts_with($interface, $namespace . '\\') && !in_array($interface, self::MODULE_INTERFACES, true)) {
					$errors[] = 'Port or probe without a registration: ' . $class . ' implements ' . $interface;
				}
			}
		}
		return $errors;
	}

	/** @param ReflectionClass $class Tool reflection. @return int Constructor work collaborators. */
	public static function workCount(ReflectionClass $class): int {
		return count(array_filter($class->getConstructor()?->getParameters() ?? [], static fn ($p) => $p->getType() instanceof \ReflectionNamedType && !$p->getType()->isBuiltin()));
	}

}
