<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Forms;

use OCA\Mcp\Modules\Forms\FormsModule;
use Psr\Container\ContainerInterface;

/** Preserves the seven legacy fixture collaborators while constructing the catalog's tools from the same graph. */
final class FormsTestModule {
	public static function build(object ...$handlers): FormsModule {
		$classes = FormsModule::catalog(); $services = [];
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
