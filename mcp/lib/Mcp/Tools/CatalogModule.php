<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

use LogicException;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use Psr\Container\ContainerInterface;

/**
 * Base of a module of the 1.3.0 contract (`<App>Module`): the module lists its tool classes in tools/list order and
 * only delegates. Reading the definitions builds no tool and no service: they come from the static
 * {@see Tool::definition()} of each class. A tool is resolved from the container the first time it is called or
 * previewed, and then reused for the request.
 *
 * The catalog is checked when it is read: every class implements {@see Tool}, every tool whose operation writes
 * implements {@see WriteTool}, and no name repeats. A broken catalog throws instead of publishing a write that
 * cannot show its plan.
 *
 * The registry treats this module as any {@see ToolModule}; behaviour notes and a custom plan body are optional
 * overrides, empty by default.
 */
abstract class CatalogModule implements ToolModule, PreviewsWrites, RendersPlans, ToolGuideNotes {
	/** @var array<class-string<CatalogModule>, array<string, array{0: class-string<Tool>, 1: ToolDefinition}>> checked catalog per module class */
	private static array $checked = [];

	/** @var array<string, Tool> tools already resolved, by name */
	private array $tools = [];

	/**
	 * @param ContainerInterface $container the app container the tools are resolved from
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}

	/**
	 * The tool classes of this module, in tools/list order. A new tool goes at the end.
	 *
	 * @return list<class-string<Tool>> tool classes
	 */
	abstract public static function catalog(): array;

	/**
	 * The DI registration of this module, run by Application for every module of Application::MODULES.
	 *
	 * @return class-string<ModuleRegistration>|null the registration class, or null when the module needs none
	 */
	public static function registration(): ?string {
		return null;
	}

	/**
	 * The definitions of the catalog, checked, without building any tool.
	 *
	 * @return list<ToolDefinition> definitions in catalog order
	 * @throws LogicException when a class is not a Tool, a write is not a WriteTool or a name repeats
	 */
	public static function toolDefinitions(): array {
		return array_values(array_map(static fn (array $entry): ToolDefinition => $entry[1], self::checked()));
	}

	/**
	 * @return list<array<string, mixed>> definitions in the array shape the registry reads
	 * @throws LogicException when the catalog is broken
	 */
	public function definitions(): array {
		return array_map(static fn (ToolDefinition $definition): array => $definition->toArray(), static::toolDefinitions());
	}

	/**
	 * @param string $name tool name as listed by definitions()
	 * @param array<string, mixed> $arguments arguments already validated against the tool's input schema
	 * @param string $userId authenticated user id
	 * @return array<string, mixed> MCP tool result
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return $this->tool($name)->execute($arguments, new ToolContext($userId));
	}

	/**
	 * @param string $name tool name of a writing definition of this module
	 * @param array<string, mixed> $arguments arguments already validated against the tool schema
	 * @param string $userId authenticated user
	 * @return array<string, mixed> the plan
	 * @throws LogicException when the tool does not write
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		$tool = $this->tool($name);
		if (!$tool instanceof WriteTool) {
			throw new LogicException("$name is not a WriteTool");
		}
		return $tool->preview($arguments, new ToolContext($userId));
	}

	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan the plan returned by preview()
	 * @return string|null null: the generic plan body
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return null;
	}

	/**
	 * @return list<string> no behaviour notes
	 */
	public function guideNotes(): array {
		return [];
	}

	/**
	 * Resolves a tool of the catalog the first time it is needed.
	 *
	 * @param string $name tool name
	 * @return Tool the tool
	 * @throws UnknownToolException when the catalog has no tool of that name
	 * @throws LogicException when the container answers something else than the listed class
	 */
	private function tool(string $name): Tool {
		if (isset($this->tools[$name])) {
			return $this->tools[$name];
		}
		$class = self::checked()[$name][0] ?? throw new UnknownToolException();
		$tool = $this->container->get($class);
		if (!$tool instanceof $class) {
			throw new LogicException("the container did not resolve $class");
		}
		return $this->tools[$name] = $tool;
	}

	/**
	 * Reads and checks the catalog of the calling module class once per request.
	 *
	 * @return array<string, array{0: class-string<Tool>, 1: ToolDefinition}> class and definition, by tool name
	 * @throws LogicException when a class is not a Tool, a write is not a WriteTool or a name repeats
	 */
	private static function checked(): array {
		if (isset(self::$checked[static::class])) {
			return self::$checked[static::class];
		}
		$entries = [];
		foreach (static::catalog() as $class) {
			if (!is_subclass_of($class, Tool::class)) {
				throw new LogicException(static::class . ": $class does not implement " . Tool::class);
			}
			$definition = $class::definition();
			if (isset($entries[$definition->name])) {
				throw new LogicException(static::class . ": tool name {$definition->name} is listed twice");
			}
			if (WriteGate::isWrite($definition->toArray()) && !is_subclass_of($class, WriteTool::class)) {
				throw new LogicException(static::class . ": $class writes ({$definition->operation}) but does not implement " . WriteTool::class);
			}
			$entries[$definition->name] = [$class, $definition];
		}
		return self::$checked[static::class] = $entries;
	}
}
