<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Tests\Architecture\Dependencies\ToolContracts;

/** Future module Tools folders and catalogs must agree; fixtures keep the rule active before the pilot. */
final class ThinToolsTest extends ArchitectureCase {
	public function testEveryFinalToolIsPublishedAndThin(): void {
		$catalog = [];
		foreach (Application::MODULES as $module) {
			if (!is_subclass_of($module, CatalogModule::class)) { continue; }
			foreach ($module::catalog() as $tool) { self::assertSame([], ToolContracts::reflected($tool), $tool); $catalog[] = $tool; }
		}
		$files = [];
		foreach (self::tree()->classes as $class => $path) {
			if (preg_match('#^lib/Modules/[^/]+/Tools/.+\.php$#', $path)) { $files[] = $class; }
		}
		sort($files); sort($catalog);
		self::assertSame([], ToolContracts::coverage($catalog, $files));
	}
	public function testThinReadAndWriteControlsAndMutants(): void {
		$read = \OCA\Mcp\Tests\Architecture\Inventory\CodeFixture::php('<?php use OCA~Mcp~Mcp~Tools~Tool; final class ReadNote implements Tool {}');
		$write = \OCA\Mcp\Tests\Architecture\Inventory\CodeFixture::php('<?php use OCA~Mcp~Mcp~Tools~WriteTool; final class EditNote implements WriteTool {}');
		self::assertSame([], ToolContracts::thin($read, false));
		self::assertSame([], ToolContracts::thin($write, true));
		foreach ([str_replace('final ', '', $read), $read . ' class Extra {}', $read . ' use OCP\\Files\\Node;', $read . ' $c->get("OCA\\\\Deck\\\\Service\\\\CardService");'] as $source) { self::assertNotSame([], ToolContracts::thin($source, false)); }
		self::assertNotSame([], ToolContracts::thin($read, true));
		self::assertSame([], ToolContracts::coverage(['ToolClass'], ['ToolClass']));
		self::assertNotSame([], ToolContracts::coverage([], ['ToolClass']));
		self::assertSame(1, ToolContracts::workCount(new \ReflectionClass(OneWorkTool::class)));
		self::assertSame(2, ToolContracts::workCount(new \ReflectionClass(TwoWorkTool::class)));
		self::assertSame([], ToolContracts::delegation(new \ReflectionClass(OneWorkTool::class), false));
		self::assertSame([], ToolContracts::delegation(new \ReflectionClass(PlannedWriteTool::class), true));
		foreach ([[TwoWorkTool::class, false], [OneWorkTool::class, true], [ThreeWorkWriteTool::class, true], [PreviewCallsEffectTool::class, true]] as [$class, $write]) {
			self::assertNotSame([], ToolContracts::delegation(new \ReflectionClass($class), $write), $class);
		}
	}
}

final class OneWorkTool { public function __construct(public \stdClass $work) {} public function execute(): mixed { return $this->work; } }
final class TwoWorkTool { public function __construct(public \stdClass $work, public \stdClass $other) {} public function execute(): mixed { return [$this->work, $this->other]; } }
final class PlannedWriteTool {
	public function __construct(public \stdClass $effect, public \stdClass $plan) {}
	public function execute(): mixed { return $this->effect; }
	public function preview(): mixed { return $this->plan; }
}
final class ThreeWorkWriteTool {
	public function __construct(public \stdClass $effect, public \stdClass $plan, public \stdClass $extra) {}
	public function execute(): mixed { return $this->effect; }
	public function preview(): mixed { return $this->plan; }
}
final class PreviewCallsEffectTool {
	public function __construct(public \stdClass $effect, public \stdClass $plan) {}
	public function execute(): mixed { return $this->effect; }
	public function preview(): mixed { return $this->effect; }
}
