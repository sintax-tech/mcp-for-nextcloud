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
				$namespace = substr($class, 0, (int)strrpos($class, '\\'));
				$own = array_values(array_filter(array_keys(self::tree()->classes), static fn (string $c): bool => str_starts_with($c, $namespace . '\\')));
				self::assertSame([], ToolContracts::registration($class::registration(), $own, $namespace, $this->bindings($class::registration())), $class);
				$probes = $class::registration() === null ? [] : (new ($class::registration())())->probes();
				foreach ($probes as $probe) {
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
	public function testARegistrationExistsExactlyWhenTheModuleBindsSomethingOrHasAProbe(): void {
		$here = __NAMESPACE__;
		self::assertSame([], ToolContracts::registration(null, [PortlessWork::class], $here));
		self::assertNotSame([], ToolContracts::registration(null, [PortlessWork::class, PortWork::class], $here));
		self::assertSame([], ToolContracts::registration(\OCA\Mcp\Modules\Notes\NotesRegistration::class, [PortWork::class], $here, $this->bindings(\OCA\Mcp\Modules\Notes\NotesRegistration::class)));
		self::assertSame(1, $this->bindings(\OCA\Mcp\Modules\Notes\NotesRegistration::class));
		self::assertSame(0, $this->bindings(EmptyRegistrationFixture::class));
		self::assertNotSame([], ToolContracts::registration(EmptyRegistrationFixture::class, [PortlessWork::class], $here, $this->bindings(EmptyRegistrationFixture::class)));
		self::assertNotSame([], ToolContracts::registration(\stdClass::class, [], $here));
		self::assertNull(RestrictedCatalogFixture::registration());
		self::assertSame([], ToolContracts::registration(RestrictedCatalogFixture::registration(), [RestrictedCatalogFixture::class, PortlessWork::class], $here));
		self::assertNotSame([], ToolContracts::registration(RestrictedCatalogFixture::registration(), [RestrictedCatalogFixture::class, PortWork::class], $here));
	}
	/**
	 * @param string|null $registration a module registration
	 * @return int services it registers, counted on a recording context
	 */
	private function bindings(?string $registration): int {
		if ($registration === null) { return 0; }
		$count = 0;
		$context = $this->createMock(\OCP\AppFramework\Bootstrap\IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(static function () use (&$count): void { $count++; });
		(new $registration())->register($context);
		return $count;
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

final class PortlessWork { public function run(): string { return ''; } }
final class PortWork implements \OCA\Mcp\Mcp\Resources\NoteResource { public function read(string $userId, int $id): string { return ''; } }
final class RestrictedCatalogFixture extends \OCA\Mcp\Mcp\Tools\CatalogModule implements \OCA\Mcp\Mcp\Tools\RestrictedModule {
	public static function catalog(): array { return []; }
	public function permits(string $userId): bool { return true; }
	public function audit(string $tool, string $userId, string $outcome, array $arguments): void {}
}
/** Review control M3 of RE-13: a registration that binds nothing and has no probe. */
final class EmptyRegistrationFixture implements \OCA\Mcp\Mcp\Tools\ModuleRegistration {
	public function register(\OCP\AppFramework\Bootstrap\IRegistrationContext $context): void {}
	public function probes(): array { return []; }
}
