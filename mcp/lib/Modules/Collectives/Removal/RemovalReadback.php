<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Removal;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\TeamsGateway;
use OCA\Mcp\Modules\Collectives\Membership\MemberReceipt;
use OCA\Mcp\Modules\Collectives\Membership\MemberTarget;

/**
 * Reads the team again after a removal Teams accepted, until the member is gone or the wait runs out. Teams removes
 * a member in the background, as for its own interface: within a web request `MemberRemove` is an asynchronous item,
 * so `removeMember()` stores the event and hands it to a loopback request that answers before it deletes the member
 * (Circles 33–35 `FederatedEventService::newEvent`, `initBroadcast`; `EventWrapperController::asyncBroadcast`). Only
 * the state is read again; the removal is never sent again. Adding and changing a level need no wait: Teams stores an
 * addition while checking it (`SingleMemberAdd::verify`) and changes a level in the request (`MemberLevel` is not
 * asynchronous).
 */
class RemovalReadback {
	/** Longest wait, in seconds: the timeout Teams gives its own loopback request (`ConfigService::configureRequest`). */
	public const SECONDS = 5;
	/** Pause between two reads, in microseconds. */
	public const PAUSE = 100_000;

	/** @param TeamsGateway $teams the collective's team */
	public function __construct(private TeamsGateway $teams) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param string $teamId the collective's team
	 * @param string $type member type, as projected
	 * @param string $id account or group id
	 * @return array{status:string, level:string}|null the membership read last: null once the member is gone, or as it
	 *     still is when the wait ran out
	 * @throws ToolFailure when the team cannot be read
	 */
	public function observe(string $uid, string $teamId, string $type, string $id): ?array {
		$deadline = $this->now() + self::SECONDS;
		while (true) {
			$observed = MemberReceipt::state(MemberTarget::find($this->teams->team($uid, $teamId)['members'], $type, $id));
			if ($observed === null || $this->now() >= $deadline) {
				return $observed;
			}
			$this->pause();
		}
	}

	/** @return float monotonic clock, in seconds */
	protected function now(): float {
		return hrtime(true) / 1e9;
	}

	/** @return void waits {@see self::PAUSE} before the next read */
	protected function pause(): void {
		usleep(self::PAUSE);
	}
}
