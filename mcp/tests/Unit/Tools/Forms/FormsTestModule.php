<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms;

use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Mcp\Tools\CatalogModule;
use Psr\Container\ContainerInterface;

/** Preserves the seven legacy fixture collaborators while constructing the catalog's tools from the same graph. */
final class FormsTestModule {
	public static function build(object ...$handlers): FormsModule {
		if (!is_subclass_of(FormsModule::class, CatalogModule::class)) { return new FormsModule(...$handlers); }
		// Only the seven 1.2.5 tools have legacy fixture collaborators; the structure tools are built in their own tests.
		$classes = array_slice(FormsModule::catalog(), 0, 7); $services = [];
		foreach ($classes as $i => $class) {
			if ($i < 4) { $services[$class] = new $class($handlers[$i]); continue; }
			$flow = (new \ReflectionProperty($handlers[$i], 'flow'))->getValue($handlers[$i]);
			$preview = ['Creation\\CreateFormPreview', 'Editing\\UpdateQuestionPreview', 'Editing\\UpdateOptionPreview'][$i - 4];
			$preview = 'OCA\\Mcp\\Modules\\Forms\\' . $preview;
			$services[$class] = new $class($handlers[$i], new $preview($flow));
		}
		return new FormsModule(new class($services) implements ContainerInterface {
			public function __construct(private array $services) { }
			public function get(string $id): mixed { return $this->services[$id] ?? throw new \LogicException($id); }
			public function has(string $id): bool { return isset($this->services[$id]); }
		});
	}
}
