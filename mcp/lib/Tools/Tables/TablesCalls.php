<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Runs one call into the Tables app and turns its errors into fixed answers that say nothing of the table, view or
 * user involved: a missing or forbidden table or view (OCA\Tables\Errors\NotFoundError, PermissionError, and the
 * DoesNotExistException of the mappers) reads as not found; anything else as the Tables app failing. The exception
 * messages of the Tables app, which name ids and users, never reach the answer.
 */
final class TablesCalls {
	/** Exceptions of the Tables app that mean "not yours to open, or not there". */
	private const NOT_FOUND = [
		'OCA\\Tables\\Errors\\NotFoundError',
		'OCA\\Tables\\Errors\\PermissionError',
		'OCP\\AppFramework\\Db\\DoesNotExistException',
	];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The result of the call.
	 *
	 * @template T
	 * @param callable(): T $call the call into the Tables app
	 * @return T its result
	 * @throws ToolFailure when the table or view is not the user's, or the Tables app failed
	 */
	public static function run(callable $call): mixed {
		try {
			return $call();
		} catch (ToolFailure $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ToolFailure(self::isNotFound($e) ? TablesMessages::notFound() : TablesMessages::failed(), 0, $e);
		}
	}

	/**
	 * Whether an exception of the Tables app means the table or view is not the user's, or not there.
	 *
	 * @param \Throwable $e the exception
	 * @return bool true for not found or forbidden
	 */
	public static function isNotFound(\Throwable $e): bool {
		foreach (self::NOT_FOUND as $class) {
			if ($e instanceof $class) {
				return true;
			}
		}
		return false;
	}
}
