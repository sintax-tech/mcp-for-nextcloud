<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Tables\Reading\ColumnLookup;
use OCA\Mcp\Modules\Tables\Reading\ListRowsHandler;
use OCA\Mcp\Modules\Tables\Reading\RowReader;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The row reader and its handler wired over a fake app, as the container wires them, with the session user the test
 * chooses (the caller by default; another user or nobody to prove the session guard), and the reader's largest cursor
 * offset when a test needs the boundary without that many rows.
 */
final class TablesRowsWiring {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The row reader.
	 *
	 * @param FakeTablesApp $app the app
	 * @param string|null $sessionUser UID the session holds, null for no session user
	 * @param int|null $maxOffset largest cursor offset, null for the reader's own
	 * @return RowReader the reader
	 */
	public static function reader(FakeTablesApp $app, ?string $sessionUser, ?int $maxOffset = null): RowReader {
		$stub = \Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class);
		$session = $stub(IUserSession::class);
		$user = null;
		if ($sessionUser !== null) {
			$user = $stub(IUser::class);
			$user->method('getUID')->willReturn($sessionUser);
		}
		$session->method('getUser')->willReturn($user);
		$config = $stub(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$parts = [$app, new ColumnLookup($app), new TablesSessionGuard($session), new PlanState($config)];
		if ($maxOffset === null) {
			return new RowReader(...$parts);
		}
		return new class($maxOffset, ...$parts) extends RowReader {
			/**
			 * Receives the boundary and the reader's parts.
			 *
			 * @param int $boundary largest cursor offset
			 * @param mixed ...$parts the reader's constructor arguments
			 * @return void
			 */
			public function __construct(private int $boundary, mixed ...$parts) {
				parent::__construct(...$parts);
			}

			/**
			 * The boundary the test chose.
			 *
			 * @return int the offset
			 */
			protected function maxOffset(): int {
				return $this->boundary;
			}
		};
	}

	/**
	 * The rows handler.
	 *
	 * @param TestCase $test the running test
	 * @param FakeTablesApp $app the app
	 * @param string|null $sessionUser UID the session holds, null for no session user
	 * @return ListRowsHandler the handler
	 */
	public static function handler(TestCase $test, FakeTablesApp $app, ?string $sessionUser): ListRowsHandler {
		return new ListRowsHandler(self::reader($app, $sessionUser), TablesScene::links($test));
	}
}
