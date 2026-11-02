<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesHandlers;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Modules\Tables\TablesModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Tables module publishes four read tools and two row write tools of the Tables app with closed schemas, the grant
 * catalog offers reading Tables, granted by default, and creating and editing rows, denied by default and granted apart,
 * and the registry hides the tools while Tables is off for the user, older than 1.0.0, denied to the user, or not an app
 * Guests allows the user.
 */
final class TablesModuleTest extends TestCase {
	/** The read tools of the module, in tools/list order. */
	private const TOOLS = ['tables_list_tables', 'tables_list_views', 'tables_list_columns', 'tables_list_rows'];

	/** The write tools of the module and their operations. */
	private const WRITES = ['tables_create_row' => 'create', 'tables_update_row' => 'edit'];

	/** @var GrantPolicy the policy of the registry */
	private GrantPolicy $policy;

	/**
	 * A fresh policy for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
	}

	/**
	 * Every tool reads, carries the Tables app id and a closed schema without confirm; listings page, columns and rows take a
	 * table or a view.
	 *
	 * @return void
	 */
	public function testTheModulePublishesFourReadToolsOfTheTablesApp(): void {
		$all = TablesHandlers::module($this, TablesScene::app())->definitions();
		$this->assertSame([...self::TOOLS, ...array_keys(self::WRITES)], array_column($all, 'name'));
		$definitions = array_slice($all, 0, 4);
		foreach ($definitions as $definition) {
			$this->assertSame(['tables', 'read', 'tables'], [$definition['module'], $definition['operation'], $definition['app']]);
			$this->assertFalse($definition['inputSchema']['additionalProperties']);
			$this->assertFalse(WriteGate::isWrite($definition), $definition['name']);
			$this->assertArrayNotHasKey('confirm', $definition['inputSchema']['properties']);
			$this->assertArrayNotHasKey('required', $definition['inputSchema']);
		}
		foreach ([0, 1] as $listing) {
			$this->assertSame(['limit', 'offset'], array_keys($definitions[$listing]['inputSchema']['properties']));
			$this->assertSame([1, 200, 50], [$definitions[$listing]['inputSchema']['properties']['limit']['minimum'], $definitions[$listing]['inputSchema']['properties']['limit']['maximum'], $definitions[$listing]['inputSchema']['properties']['limit']['default']]);
		}
		$this->assertSame(['table_id', 'view_id'], array_keys($definitions[2]['inputSchema']['properties']));
		$this->assertSame(['table_id', 'view_id', 'filter', 'search', 'sort', 'limit', 'cursor'], array_keys($definitions[3]['inputSchema']['properties']));
		foreach (array_slice($all, 4) as $definition) {
			$this->assertSame(['tables', self::WRITES[$definition['name']], 'tables'], [$definition['module'], $definition['operation'], $definition['app']]);
			$this->assertTrue(WriteGate::isWrite($definition), $definition['name']);
			$this->assertFalse($definition['inputSchema']['additionalProperties']);
		}
		$this->assertSame(['table_id', 'view_id', 'cells', 'confirm_shared', 'plan_state'], array_keys($all[4]['inputSchema']['properties']));
		$this->assertSame(['cells'], $all[4]['inputSchema']['required']);
		$this->assertSame([1, 100, [[['column_id' => 1, 'value' => 'text']]]], [$all[4]['inputSchema']['properties']['cells']['minItems'],
			$all[4]['inputSchema']['properties']['cells']['maxItems'], $all[4]['inputSchema']['properties']['cells']['examples']]);
		$this->assertSame(['table_id', 'view_id', 'row_id', 'cells', 'confirm_shared', 'plan_state'], array_keys($all[5]['inputSchema']['properties']));
		$this->assertSame(['row_id', 'cells'], $all[5]['inputSchema']['required']);
		$this->assertTrue(is_subclass_of(TablesModule::class, PreviewsWrites::class), 'the row writes are planned first');
	}

	/**
	 * The catalog for Tables is `read`, granted by default like the other reads, and `create` and `edit`, denied by default
	 * like every write and granted apart from each other and from `read`.
	 *
	 * @return void
	 */
	public function testTheCatalogOffersReadingTables(): void {
		$this->assertSame(['read', 'create', 'edit'], GrantPolicy::CATALOG['tables']);
		$this->assertNotContains('tables', GrantPolicy::DENIED_READS);
		$this->assertTrue($this->policy->granted('alice', 'tables', 'read'));
		$this->assertFalse($this->policy->granted('alice', 'tables', 'create'));
		$this->assertFalse($this->policy->granted('alice', 'tables', 'edit'));
		// A policy stored before the row writes existed holds `read` only: after the upgrade the writes stay denied.
		$this->policy->setGrant('bob', 'tables', 'read', true);
		$this->assertSame([true, false, false], [$this->policy->granted('bob', 'tables', 'read'), $this->policy->granted('bob', 'tables', 'create'),
			$this->policy->granted('bob', 'tables', 'edit')]);
		$this->policy->setGrant('alice', 'tables', 'create', true);
		$this->policy->setGrant('alice', 'tables', 'read', false);
		$this->assertSame([true, false, false], [$this->policy->granted('alice', 'tables', 'create'), $this->policy->granted('alice', 'tables', 'read'),
			$this->policy->granted('alice', 'tables', 'edit')]);
		$this->assertSame(['tables_create_row'], array_values(array_intersect(array_column($this->registry(true, '2.3.2')->list('alice'), 'name'),
			[...self::TOOLS, ...array_keys(self::WRITES)])), 'create alone, without read');
	}

	/**
	 * The published schema refuses an empty cell list before anything of Tables, for both writes, with the grants given.
	 *
	 * @return void
	 */
	public function testAnEmptyCellListIsAnInvalidCall(): void {
		$this->policy->setGrant('alice', 'tables', 'create', true);
		$this->policy->setGrant('alice', 'tables', 'edit', true);
		foreach ([['tables_create_row', ['table_id' => 1, 'cells' => []]], ['tables_update_row', ['table_id' => 1, 'row_id' => 5, 'cells' => []]]] as [$tool, $arguments]) {
			try {
				$this->registry(true, '2.3.2')->call($tool, $arguments, 'alice');
				$this->fail($tool . ' accepted an empty list');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString('cells', $e->getMessage(), $tool);
			}
		}
	}

	/**
	 * A tool name the module does not have is an invalid call.
	 *
	 * @return void
	 */
	public function testAnUnknownToolIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		TablesHandlers::module($this, TablesScene::app())->call('tables_delete_rows', [], 'alice');
	}

	/**
	 * Listed with Tables 1.0.0 or later enabled for the user; hidden and refused when off, at 0.9.12, when the user's
	 * grant denies reading Tables, or for a guest whose Guests app list lacks Tables.
	 *
	 * @return void
	 */
	public function testTheRegistryGatesTheTools(): void {
		$this->assertSame(self::TOOLS, $this->listed(true, '1.0.0'));
		$this->assertSame(self::TOOLS, $this->listed(true, '2.3.2'));
		$this->assertSame([], $this->listed(false, '2.2.2'));
		$this->assertSame([], $this->listed(true, '0.9.12'));
		$this->assertSame([], $this->listed(true, '2.2.2', ['alice' => ['files', 'calendar']]));
		$this->assertSame(self::TOOLS, $this->listed(true, '2.2.2', ['alice' => ['files', 'tables']]));
		$this->policy->setGrant('alice', 'tables', 'read', false);
		$this->assertSame([], $this->listed(true, '2.2.2'));

		$this->expectException(InvalidArgumentException::class);
		$this->registry(true, '2.2.2')->call('tables_list_tables', [], 'alice');
	}

	/**
	 * Names of the Tables tools the registry lists for alice.
	 *
	 * @param bool $enabled whether Tables is enabled for the user
	 * @param string $version installed Tables version
	 * @param array<string, list<string>>|null $guestApps apps Guests allows each guest, or null without Guests
	 * @return list<string> listed tool names
	 */
	private function listed(bool $enabled, string $version, ?array $guestApps = null): array {
		return array_values(array_intersect(array_column($this->registry($enabled, $version, $guestApps)->list('alice'), 'name'), self::TOOLS));
	}

	/**
	 * The registry with the Tables module only.
	 *
	 * @param bool $enabled whether Tables is enabled for the user
	 * @param string $version installed Tables version
	 * @param array<string, list<string>>|null $guestApps apps Guests allows each guest, or null without Guests
	 * @return ToolRegistry the registry
	 */
	private function registry(bool $enabled, string $version, ?array $guestApps = null): ToolRegistry {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'tables' && $enabled);
		$apps->method('getAppVersion')->willReturn($version);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$guests = null;
		if ($guestApps !== null) {
			$guests = $this->createMock(GuestRestrictions::class);
			$guests->method('allowsApp')->willReturnCallback(static fn (string $uid, string $app): bool => $app === '' || !isset($guestApps[$uid]) || in_array($app, $guestApps[$uid], true));
		}
		return new ToolRegistry([TablesHandlers::module($this, TablesScene::app())], $this->policy, $apps, $users, $this->createMock(LoggerInterface::class), $guests);
	}
}
