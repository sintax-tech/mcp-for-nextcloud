<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps expected Deck refusals below warning without changing client messages or authorization.
 *
 * Callers retain the separate warning paths for partial, saved or uncertain writes. Native failures
 * from a write reach operation() only after the handler's existing read-back found no saved result.
 */
final class DeckFailureLog {
	/**
	 * Classify only explicit rule, permission, absence, archived-item and conflict exception types.
	 *
	 * StatusException is deliberately excluded: its native default status is 500. Mapper failures,
	 * session failures and arbitrary exceptions remain unexpected; exception text is never inspected.
	 *
	 * @param Throwable $failure Failure whose phase the caller has already established.
	 * @return bool Whether this is an expected refusal before a write, never a partial refusal.
	 */
	private static function expected(Throwable $failure): bool {
		if ($failure instanceof \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException) {
			return !$failure->afterWrite();
		}
		return $failure instanceof \OCA\Mcp\Modules\Deck\Failures\DeckConflictException
			|| is_a($failure, 'OCA\\Deck\\NoPermissionException')
			|| is_a($failure, 'OCA\\Deck\\ArchivedItemException')
			|| is_a($failure, 'OCA\\Deck\\Exceptions\\ConflictException')
			|| is_a($failure, 'OCP\\AppFramework\\Db\\DoesNotExistException');
	}

	/**
	 * Log a preview refusal at debug or preserve the warning for an unexpected preview failure.
	 *
	 * @param LoggerInterface $logger Existing module logger.
	 * @param string $tool Technical tool name, never a user-provided title.
	 * @param Throwable $failure Failure logged by class only, without its message or payload.
	 * @return void
	 */
	public static function plan(LoggerInterface $logger, string $tool, Throwable $failure): void {
		$context = ['tool' => $tool, 'exception' => $failure::class];
		if (self::expected($failure)) {
			$logger->debug('MCP Deck plan failed: {tool} ({exception})', $context);
		} else {
			$logger->warning('MCP Deck plan failed: {tool} ({exception})', $context);
		}
	}

	/**
	 * Log a normal refusal at debug while retaining error for unexpected operation failures.
	 *
	 * Partial refusals, unconfirmed writes and saved writes are handled by the existing caller first.
	 * This method neither performs read-back nor infers the write phase from a native exception class.
	 *
	 * @param LoggerInterface $logger Existing handler logger.
	 * @param string $tool Technical tool name.
	 * @param Throwable $failure Failure already classified by the handler's write/read-back flow.
	 * @return void
	 */
	public static function operation(LoggerInterface $logger, string $tool, Throwable $failure): void {
		$context = ['tool' => $tool, 'exception' => $failure::class];
		if (self::expected($failure)) {
			$logger->debug('MCP Deck tool refused or conflicted: {tool} ({exception})', $context);
		} else {
			$logger->error('MCP Deck tool failed: {tool} ({exception})', $context);
		}
	}
}
