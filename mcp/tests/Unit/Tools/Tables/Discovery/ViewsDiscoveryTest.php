<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Discovery;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesDouble;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Modules\Tables\Reading\TablesDiscovery;
use OCA\Mcp\Modules\Tables\Reading\ViewsDiscovery;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Tables\Errors\InternalError;
use PHPUnit\Framework\TestCase;

/**
 * The views of a user come from the tables they manage, the views shared with them and their applications (contexts),
 * each authorized again for the user before it is kept, once each with every place it was found, in table then view
 * order. The views of a table the user only reads are not listed, as in the Tables app.
 */
final class ViewsDiscoveryTest extends TestCase {
	/**
	 * Owner, manage share, read-only share, view share, application and nothing: each user gets exactly the views the
	 * Tables app shows them, with where each one was found.
	 *
	 * @return void
	 */
	public function testEachUserGetsTheViewsTheyCanOpen(): void {
		$app = TablesScene::app();
		$this->assertSame([[21, ['table']], [22, ['table']]], $this->found($app, 'alice'));
		$this->assertSame([[21, ['table']], [22, ['table']]], $this->found($app, 'frank'));
		$this->assertSame([], $this->found($app, 'carol'), 'a read-only table lists no views');
		$this->assertSame([[21, ['shared']]], $this->found($app, 'bob'));
		$this->assertSame([[22, ['context']]], $this->found($app, 'erin'));
		$this->assertSame([], $this->found($app, 'dave'));
		$this->assertSame(['alice', 'frank'], array_values(array_unique(array_column($app->callsOf('ViewService', 'findAll'), 1))), 'views of a table are asked only for the tables the user manages');
	}

	/**
	 * A view found in several places is listed once, with each place.
	 *
	 * @return void
	 */
	public function testAViewFoundTwiceIsListedOnceWithEachPlace(): void {
		$app = TablesScene::app()->sharingView(21, 'frank')->withContext('frank', [['node_id' => 21, 'node_type' => 1, 'permissions' => 1], ['node_id' => 1, 'node_type' => 0, 'permissions' => 1]]);
		$this->assertSame([[21, ['table', 'shared', 'context']], [22, ['table']]], $this->found($app, 'frank'));
	}

	/**
	 * A view the permissions refuse is dropped before anything but its id is read, and an application naming a view
	 * the user cannot open (anymore) is skipped.
	 *
	 * @return void
	 */
	public function testAViewThePermissionsRefuseIsDropped(): void {
		$app = TablesScene::app()->withStaleView(22, 'bob')->withContext('bob', [['node_id' => 99, 'node_type' => 1, 'permissions' => 1]]);
		$this->assertSame([[21, ['shared']]], $this->found($app, 'bob'));
		$this->assertSame([], $app->callsOf('View#22', 'getTitle'), 'the refused view is never read');
		$this->assertSame([[99, true, 'bob']], $app->callsOf('ViewService', 'find'));
	}

	/**
	 * Any other failure of the Tables app while reading an application's view is not swallowed.
	 *
	 * @return void
	 */
	public function testAnotherFailureIsNotSwallowed(): void {
		$app = new class extends FakeTablesApp {
			protected function resolve(string $class, string $userId): object {
				$service = parent::resolve($class, $userId);
				return $class !== 'OCA\\Tables\\Service\\ViewService' ? $service : new TablesDouble('ViewService', [
					'findSharedViewsWithMe' => static fn (): array => [],
					'find' => static fn (): never => throw new InternalError('db down for erin'),
				], $this->journal);
			}
		};
		$app->withContext('erin', [['node_id' => 22, 'node_type' => 1, 'permissions' => 1]]);
		try {
			(new ViewsDiscovery($app))->views('erin', []);
			$this->fail('expected the failure');
		} catch (ToolFailure $e) {
			$this->assertSame(TablesMessages::failed(), $e->getMessage());
		}
	}

	/**
	 * The views a user finds, with where.
	 *
	 * @param FakeTablesApp $app the app
	 * @param string $uid the user
	 * @return list<array{0:int, 1:list<string>}> view ids and places
	 */
	private function found(FakeTablesApp $app, string $uid): array {
		$tables = (new TablesDiscovery($app))->tables($uid);
		return array_map(static fn (array $e): array => [$e['view']->getId(), $e['via']], (new ViewsDiscovery($app))->views($uid, $tables));
	}
}
