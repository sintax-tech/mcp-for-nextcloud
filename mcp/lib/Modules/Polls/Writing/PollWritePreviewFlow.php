<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Writing;

use OCA\Mcp\Modules\Polls\Contracts\PollWriteReader;
use OCA\Mcp\Modules\Polls\Contracts\PollMutation;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Effect-free context lifecycle: this dependency cannot call a native mutation. */
final class PollWritePreviewFlow {
	/**
	 * @param PollWriteReader $reader Read-only native context.
	 * @param PollWritePlan $plans Stateless signed plans using verb-specific rules.
	 * @return void
	 */
	public function __construct(private PollWriteReader $reader, private PollWritePlan $plans) {
	}

	/**
	 * @param array<string,mixed> $request Validated tool-specific input.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> Complete signed plan without native effects.
	 * @throws ToolFailure If native admission or observation fails.
	 * @throws \InvalidArgumentException If a verb rule refuses the request.
	 */
	public function plan(array $request, string $uid): array {
		try {
			$this->reader->open($uid, PollMutation::from($request['tool'])->operation());
			return $this->plans->of($uid, $request, $this->reader->snapshot($uid, $request));
		} catch (ToolFailure|\InvalidArgumentException $error) {
			throw $error;
		} catch (\Throwable) {
			throw PollsRefusal::unavailable();
		} finally {
			try {
				$this->reader->close();
			} catch (\Throwable) {
				throw PollsRefusal::unavailable();
			}
		}
	}
}
