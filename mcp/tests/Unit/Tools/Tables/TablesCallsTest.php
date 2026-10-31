<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables;

use OCA\Mcp\Tools\Tables\TablesCalls;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/tables_stubs.php';

/**
 * A failure of the Tables app becomes a fixed message: missing and forbidden read the same, anything else is the app
 * failing; the message of the Tables exception, which names ids and users, never reaches the answer.
 */
final class TablesCallsTest extends TestCase {
	/**
	 * Not found, forbidden and a missing row of a mapper read as not found; anything else as failed.
	 *
	 * @return void
	 */
	public function testFailuresBecomeFixedMessages(): void {
		foreach ([
			[new NotFoundError('Table 7 of alice not found'), TablesMessages::notFound()],
			[new PermissionError('PermissionError: can not read table with id 7 for bob'), TablesMessages::notFound()],
			[new DoesNotExistException('row 7'), TablesMessages::notFound()],
			[new InternalError('SQL error near alice'), TablesMessages::failed()],
			[new \TypeError('argument 1 of bob'), TablesMessages::failed()],
		] as [$error, $message]) {
			try {
				TablesCalls::run(static fn (): never => throw $error);
				$this->fail('expected a failure');
			} catch (ToolFailure $e) {
				$this->assertSame($message, $e->getMessage());
				$this->assertSame($error, $e->getPrevious());
			}
		}
	}

	/**
	 * A failure of the module passes as it is, and a result is returned as it is.
	 *
	 * @return void
	 */
	public function testOwnFailuresAndResultsPass(): void {
		$own = new ToolFailure('own');
		try {
			TablesCalls::run(static fn (): never => throw $own);
		} catch (ToolFailure $e) {
			$this->assertSame($own, $e);
		}
		$this->assertSame([1], TablesCalls::run(static fn (): array => [1]));
	}
}
