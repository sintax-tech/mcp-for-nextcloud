<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** End-to-end preparation authorization: no SchemaGateway mock. */
final class SchemaPreparationTest extends TestCase {
	/** @return iterable<string,array> Targeted writes and their native authorization resource. */
	public static function actions(): iterable {
		foreach ([
			'update_table' => ['table:1', ['table_id' => 1, 'patch' => ['title' => 'New']]],
			'create_column' => ['table:1', ['table_id' => 1, 'title' => 'New', 'type' => 'text', 'subtype' => 'line']],
			'update_column' => ['table:1', ['table_id' => 1, 'column_id' => 2, 'patch' => ['title' => 'New']]],
			'create_view' => ['table:1', ['table_id' => 1, 'title' => 'New']],
			'update_view' => ['view:3', ['table_id' => 1, 'view_id' => 3, 'patch' => ['title' => 'New']]],
			'share_node' => ['table:1', ['node_type' => 'table', 'node_id' => 1, 'recipient' => ['type' => 'user', 'id' => 'charlie'], 'permissions' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'manage' => false]]],
			'unshare_node' => ['table:1', ['node_type' => 'table', 'node_id' => 1, 'share_id' => 9]],
		] as $action => [$resource, $args]) yield $action => [$action, $resource, $args];
	}
	/** @param string $action Write. @param string $resource Authorization target. @param array $args Intent. @return void */
	#[DataProvider('actions')]
	public function testRealPreparationRequiresReadAndManageAndKeepsShared(string $action, string $resource, array $args): void {
		$scene = new SchemaPreparationScene($this);
		$this->assertTrue($scene->preparation->prepare($action, $args, 'alice', '2.3.2')['shared']);
		$scene->manage[$resource] = false;
		try { $scene->preparation->prepare($action, $args, 'alice', '2.3.2'); $this->fail('Manage bypassed'); }
		catch (ToolFailure $error) { $this->assertSame(CommonMessages::forbidden(), $error->getMessage()); }
		$scene->read[$resource] = false;
		try { $scene->preparation->prepare($action, $args, 'alice', '2.3.2'); $this->fail('Read bypassed'); }
		catch (ToolFailure $error) { $this->assertSame(CommonMessages::notFound(), $error->getMessage()); }
		[$kind, $id] = explode(':', $resource);
		unset($scene->entities[$kind][(int)$id]);
		try { $scene->preparation->prepare($action, $args, 'alice', '2.3.2'); $this->fail('Missing target accepted'); }
		catch (ToolFailure $error) { $this->assertSame(CommonMessages::notFound(), $error->getMessage()); }
	}
	/** @return void Every nested view must be readable, managed and bound to the table. */
	public function testCreateColumnAuthorizesEveryNestedView(): void {
		foreach (['missing', 'unreadable', 'unmanaged', 'foreign'] as $failure) {
			$scene = new SchemaPreparationScene($this);
			$id = $failure === 'missing' ? 999 : ($failure === 'foreign' ? 4 : 3);
			if ($failure === 'unreadable') $scene->read['view:3'] = false;
			if ($failure === 'unmanaged') $scene->manage['view:3'] = false;
			$args = ['table_id' => 1, 'title' => 'New', 'type' => 'text', 'subtype' => 'line', 'view_ids' => [$id]];
			try { $scene->preparation->prepare('create_column', $args, 'alice', '2.3.2'); $this->fail('Nested ID bypassed: ' . $failure); }
			catch (ToolFailure $error) { $this->assertSame($failure === 'unmanaged' ? CommonMessages::forbidden() : CommonMessages::notFound(), $error->getMessage()); }
		}
	}
	/** @return void Existing target IDs are bound to the supplied parent before revealing metadata. */
	public function testForeignUpdateTargetsAndMissingIds(): void {
		foreach (['update_column' => 'column_id', 'update_view' => 'view_id'] as $action => $field) {
			foreach (['missing', 'foreign'] as $failure) {
				$scene = new SchemaPreparationScene($this);
				$id = $failure === 'missing' ? 999 : ($field === 'column_id' ? 2 : 3);
				try { $scene->preparation->prepare($action, ['table_id' => 99, $field => $id, 'patch' => ['title' => 'New']], 'alice', '2.3.2'); $this->fail('Foreign target accepted'); }
				catch (ToolFailure $error) { $this->assertSame(CommonMessages::notFound(), $error->getMessage()); }
			}
		}
	}
	/** @return void The assembly must actually invoke native sharing policy. */
	public function testDisabledSharingCannotBeBypassedByPreparation(): void {
		$scene = new SchemaPreparationScene($this);
		$scene->disabled = true;
		$args = iterator_to_array(self::actions())['share_node'][2];
		$this->expectExceptionMessage(CommonMessages::forbidden());
		$scene->preparation->prepare('share_node', $args, 'alice', '2.3.2');
	}
}
