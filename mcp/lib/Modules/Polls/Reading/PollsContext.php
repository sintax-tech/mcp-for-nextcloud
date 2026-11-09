<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Reading;

use OCA\Mcp\Modules\Polls\Integration\PollsServices;
use OCA\Mcp\Modules\Polls\Integration\PollsSessionGuard;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Platform\Logging\ExceptionDetails;

/**
 * One native authenticated, non-token Polls context. Cleans both before and in finally, and returns only projected
 * scalars/arrays, so late serialization cannot recreate ncPollsUserId after cleanup. Never impersonates another actor.
 */
final class PollsContext {
	/**
	 * Receives the resolver and identity guard.
	 * @param PollsServices $services native dependency resolver
	 * @param PollsSessionGuard $guard session and identity checks
	 * @param \Psr\Log\LoggerInterface $logger class-only diagnostics, no native messages/content
	 */
	public function __construct(private PollsServices $services, private PollsSessionGuard $guard, private \Psr\Log\LoggerInterface $logger) {
	}

	/**
	 * Runs all domain reads inside the native context, with safe errors and mandatory final cleanup.
	 * @template T
	 * @param string $uid authenticated actor
	 * @param callable(object,string): T $read receives native session and actual app version
	 * @param string $operation exact grant, independent of read for the PO-2 tools
	 * @return T already projected answer, no native objects
	 * @throws PollsRefusal on any identity/compatibility/native failure
	 */
	public function run(string $uid, callable $read, string $operation = 'read'): mixed {
		$native = null;
		$failure = null;
		try {
			$this->guard->caller($uid);
			$version = $this->services->prepare($uid, $operation);
			$native = $this->services->session();
			$native->cleanSession();
			$this->guard->native($native, $uid);
			return $read($native, $version);
		} catch (PollsRefusal $error) {
			$failure = $error;
			throw $error;
		} catch (\Throwable $error) {
			$failure = $error;
			$this->diagnose($error);
			throw PollsRefusal::unavailable();
		} finally {
			if ($native !== null) {
				try {
					$native->cleanSession();
				} catch (\Throwable $error) {
					$this->diagnose($error, $failure);
					throw PollsRefusal::unavailable();
				}
			}
		}
	}

	/**
	 * Records only exception classes; cleanup failure retains the original class too, never messages/tokens/data.
	 * Logging itself must not replace the original safe refusal.
	 * @param \Throwable $error native failure
	 * @param \Throwable|null $prior failure preceding final cleanup
	 */
	private function diagnose(\Throwable $error, ?\Throwable $prior = null): void {
		try {
			$message = 'MCP Polls read failed: {exception_class} (code {exception_code})';
			if ($prior !== null) { $message .= '; previous: {prior_exception_class}'; }
			$this->logger->error($message, ['app' => 'mcp', 'exception_class' => $error::class, 'exception_code' => ExceptionDetails::code($error),
				'prior_exception_class' => $prior === null ? null : $prior::class]);
		} catch (\Throwable) {
			// Preserve the safe read outcome even if diagnostics are unavailable.
		}
	}
}
