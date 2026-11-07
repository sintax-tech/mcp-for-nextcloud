<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;

/** Preserves Forms' native throttle sequence without invoking or reflecting a second dispatcher. */
class CreateNativeThrottle {
	/** Receives the public throttler and actual endpoint request IP.
	 * @param IThrottler $throttler Official request throttler.
	 * @param IRequest $request Actual MCP request.
	 */
	public function __construct(private IThrottler $throttler, private IRequest $request) {
	}
	/** Runs one native stage, distinguishing the pre-call throttle from post-call failures.
	 * @param callable():mixed $call One native controller invocation.
	 * @param callable():void $started Marks immediately before entering the controller, after the initial throttle.
	 * @return mixed Native response.
	 * @throws \Throwable Native or throttle failure, never silently retried.
	 */
	public function invoke(callable $call, callable $started): mixed {
		$ip = $this->request->getRemoteAddress();
		$this->throttler->sleepDelayOrThrowOnMax($ip, 'form');
		$started();
		try { return $call(); }
		catch (\OCA\Forms\Exception\NoSuchFormException $error) {
			$this->throttler->registerAttempt('form', $ip, ['action' => 'form']);
			$this->throttler->sleepDelayOrThrowOnMax($ip, 'form');
			throw $error;
		}
	}
}
