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
use OCA\Mcp\Tests\Unit\Contract\Oracle\ModuleBuilder;

/** Real module IDs, grants, capabilities, global uniqueness and golden publication order. */
final class CatalogContractTest extends ArchitectureCase {
	public function testAllModuleDefinitionsMatchThePublishedOrderAndContract(): void {
		$definitions = []; $capabilities = [];
		$builder = new ModuleBuilder($this);
		foreach (Application::MODULES as $class) {
			if (is_subclass_of($class, CatalogModule::class)) {
				self::assertNotNull($class::registration(), 'Migrated module must have a registration');
				$registration = new ($class::registration())();
				foreach ($registration->probes() as $probe) {
					$r = (new \ReflectionClass($probe))->newInstanceWithoutConstructor();
					$capabilities = [...$capabilities, ...$r->keys()];
				}
				$rows = array_map(static fn ($d) => $d->toArray(), $class::toolDefinitions());
			} else { $rows = $builder->build($class)->definitions(); }
			self::assertNotEmpty($rows, $class);
			$definitions = [...$definitions, ...$rows];
		}
		self::assertSame([], ToolContracts::catalog($definitions, $capabilities));
		$golden = json_decode((string)file_get_contents(dirname(__DIR__) . '/Unit/Contract/golden/discovery.all-on.json'), true, 512, JSON_THROW_ON_ERROR);
		$names = array_column($golden['tools/list']['body']['result']['tools'], 'name');
		self::assertSame(array_values(array_diff($names, ['mcp_status', 'mcp_guide', 'search', 'fetch'])), array_column($definitions, 'name'));
	}
	public function testCatalogMutantsAreRejectedAndCleanFixturePasses(): void {
		$read = ['name' => 'notes_read_note', 'module' => 'notes', 'operation' => 'read'];
		self::assertSame([], ToolContracts::catalog([$read], []));
		foreach ([[$read, $read], [array_replace($read, ['name' => 'wrong_read'])], [array_replace($read, ['operation' => 'unknown'])], [array_replace($read, ['grantAnyOf' => ['unknown']])], [array_replace($read, ['requires' => ['notes.missing']])], []] as $rows) { self::assertNotSame([], ToolContracts::catalog($rows, [])); }
		self::assertSame([], ToolContracts::catalog([['name' => 'users_search', 'module' => 'people', 'operation' => 'read']], []));
		self::assertNotSame([], ToolContracts::catalog([['name' => 'users_new', 'module' => 'people', 'operation' => 'read']], []));
		$names = ['notes_read_note', 'notes_edit_note'];
		self::assertNotSame([], ToolContracts::catalog([array_replace($read, ['name' => 'notes_edit_note', 'operation' => 'edit']), $read], [], $names));
	}
}
