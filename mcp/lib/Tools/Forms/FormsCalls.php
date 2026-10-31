<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Maps native missing/forbidden failures identically without exposing ids, owners or service messages. */
final class FormsCalls {
	/** Executes one native read through a fixed error boundary.
	 * @template T
	 * @param callable():T $work Authorized native read.
	 * @return T Its result.
	 * @throws ToolFailure On a native refusal or unexpected failure.
	 */
	public static function run(callable $work): mixed {
		try { return $work(); }
		catch (ToolFailure $error) { throw $error; }
		catch (\Throwable $error) {
			$missing = $error instanceof \OCA\Forms\Exception\NoSuchFormException
				|| $error instanceof \OCP\AppFramework\Db\DoesNotExistException;
			throw new ToolFailure($missing ? FormsMessages::notFound() : FormsMessages::unavailable(), 0, $error);
		}
	}
}
