<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCP\Share\Exceptions\AlreadySharedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs a proven duplicate-share refusal quietly, preserving warnings for every other share failure.
 *
 * GenericShareException, InvalidArgumentException and plain Exception do not establish a refusal:
 * providers and event listeners can also throw them after effects. No message-based inference is made.
 */
final class ShareFailureLog {
	/**
	 * Record the existing safe diagnostic without leaking paths, recipients, passwords or exception text.
	 *
	 * @param LoggerInterface $logger Existing sharing logger.
	 * @param string $message Existing fixed diagnostic supplied by the share or unshare caller.
	 * @param Throwable $failure Native failure; only AlreadySharedException proves the duplicate condition.
	 * @return void
	 */
	public static function record(LoggerInterface $logger, string $message, Throwable $failure): void {
		$context = ['app' => 'mcp', 'exception_class' => $failure::class];
		if ($failure instanceof AlreadySharedException) {
			$logger->debug($message, $context);
		} else {
			$logger->warning($message, $context);
		}
	}
}
