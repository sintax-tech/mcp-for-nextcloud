<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Tables\Creation\CreateRowHandler;
use OCA\Mcp\Modules\Tables\Editing\UpdateRowHandler;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Modules\Tables\Integration\TablesServices;
use OCA\Mcp\Modules\Tables\Writing\CellValueDomain;
use OCA\Mcp\Modules\Tables\Writing\RelationWriteGate;
use OCA\Mcp\Modules\Tables\Writing\RowCurrentState;
use OCA\Mcp\Modules\Tables\Writing\RowWriteColumns;
use OCA\Mcp\Modules\Tables\Writing\RowWriteFlow;
use OCA\Mcp\Modules\Tables\Writing\RowWritePlan;
use OCA\Mcp\Modules\Tables\Writing\RowWritePreflight;
use OCA\Mcp\Modules\Tables\Writing\RowWriter;
use OCA\Mcp\Modules\Tables\Writing\UsergroupWhitelist;
use OCA\Mcp\Modules\Tables\Writing\ViewDefaultsGate;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The row write flow and handlers wired over a fake app, as the container wires them, with the session user the test
 * chooses (the caller by default), the users and groups that exist, and whether Circles is enabled.
 */
final class TablesWriteWiring {
	/** Users that exist in the scene. */
	public const USERS = ['alice', 'bob', 'carol', 'dave', 'erin'];

	/** Groups that exist in the scene. */
	public const GROUPS = ['staff'];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The write flow.
	 *
	 * @param TestCase $test the running test
	 * @param TablesServices $app the app
	 * @param string|null $sessionUser UID the session holds, null for no session user
	 * @param bool $circles whether Circles is enabled
	 * @return RowWriteFlow the flow
	 */
	public static function flow(TestCase $test, TablesServices $app, ?string $sessionUser, bool $circles = false): RowWriteFlow {
		$stub = \Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class);
		$session = $stub(IUserSession::class);
		$user = null;
		if ($sessionUser !== null) {
			$user = $stub(IUser::class);
			$user->method('getUID')->willReturn($sessionUser);
		}
		$session->method('getUser')->willReturn($user);
		$guard = new TablesSessionGuard($session);
		$config = $stub(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$users = $stub(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $id): bool => in_array($id, self::USERS, true));
		$users->method('get')->willReturnCallback(static fn (string $id): ?IUser => in_array($id, self::USERS, true) ? $stub(IUser::class) : null);
		$groups = $stub(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $id): bool => in_array($id, self::GROUPS, true));
		$apps = $stub(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'circles' && $circles);
		$preflight = new RowWritePreflight($guard, new RowWriteColumns($app), new CellValueDomain(new UsergroupWhitelist($users, $groups, $apps)),
			new RelationWriteGate($app), new ViewDefaultsGate($app), new RowCurrentState($app, $guard));
		return new RowWriteFlow($preflight, new RowWritePlan(new PlanState($config), TablesScene::links($test)), new RowWriter($app, $guard));
	}

	/**
	 * The two row write handlers.
	 *
	 * @param TestCase $test the running test
	 * @param TablesServices $app the app
	 * @param string|null $sessionUser UID the session holds, null for no session user
	 * @return array{0: CreateRowHandler, 1: UpdateRowHandler} the handlers
	 */
	public static function handlers(TestCase $test, TablesServices $app, ?string $sessionUser): array {
		$flow = self::flow($test, $app, $sessionUser);
		return [new CreateRowHandler($flow), new UpdateRowHandler($flow)];
	}
}
