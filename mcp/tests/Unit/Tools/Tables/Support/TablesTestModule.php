<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use OCA\Mcp\Modules\Tables\TablesModule;
use OCA\Mcp\Modules\Tables\Creation\{CreateRowHandler, CreateRowPreview};
use OCA\Mcp\Modules\Tables\Editing\{UpdateRowHandler, UpdateRowPreview};
use Psr\Container\ContainerInterface;

/** Adapts the unchanged legacy test collaborators to catalog tool resolution. */
final class TablesTestModule {
	private static ?\WeakMap $collaborators = null;

	public static function make(object $tables, object $views, object $columns, object $rows, CreateRowHandler $createRow, UpdateRowHandler $updateRow): TablesModule {
		$parts = compact('tables', 'views', 'columns', 'rows', 'createRow', 'updateRow');
		$services = [];
		foreach ($parts as $object) {
			$services[get_class($object)] = $object;
		}
		$flow = static fn (object $handler): object => (fn () => $this->flow)->call($handler);
		$services[CreateRowPreview::class] = new CreateRowPreview($flow($createRow));
		$services[UpdateRowPreview::class] = new UpdateRowPreview($flow($updateRow));
		$container = new class($services) implements ContainerInterface {
			public function __construct(private array $services) {
			}
			public function has(string $id): bool {
				return isset($this->services[$id]) || (str_starts_with($id, 'OCA\\Mcp\\Modules\\Tables\\Tools\\') && class_exists($id));
			}
			public function get(string $id): object {
				if (isset($this->services[$id])) {
					return $this->services[$id];
				}
				if (!$this->has($id)) {
					throw new \LogicException("no Tables test service $id");
				}
				$class = new \ReflectionClass($id);
				$args = array_map(fn (\ReflectionParameter $p): object => $this->get($p->getType()->getName()), $class->getConstructor()?->getParameters() ?? []);
				return $this->services[$id] = $class->newInstanceArgs($args);
			}
		};
		$module = new TablesModule($container);
		self::$collaborators ??= new \WeakMap();
		self::$collaborators[$module] = $parts;
		return $module;
	}

	public static function handler(TablesModule $module, string $name): object {
		return self::$collaborators[$module][$name];
	}
}
