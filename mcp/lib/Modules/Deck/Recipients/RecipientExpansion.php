<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Recipients;

use Closure;
use OCP\IUser;

/**
 * Operation-local cooperative time/work limits and UID deduplication; never exposes partial results.
 */
final class RecipientExpansion {
	/**
	 * Maximum native membership/identity work steps, including overlaps, per resolution.
	 */
	public const MAX_VISITS = 5000;
	/**
	 * Maximum distinct people in a complete supported recipient set.
	 */
	public const MAX_RECIPIENTS = 1000;
	/**
	 * Maximum elapsed monotonic nanoseconds; native I/O cannot be interrupted mid-call.
	 */
	public const MAX_DURATION_NS = 2000000000;

	/**
	 * Canonical identities and names indexed with a prefix to preserve numeric-looking UIDs.
	 *
	 * @var array<string, array{uid:string,name:string}>
	 */
	private array $members = [];
	/**
	 * Native work already consumed in this operation, including repeated identities.
	 *
	 * @var int Non-negative work steps.
	 */
	private int $visits = 0;
	/**
	 * Monotonic start instant for the current operation.
	 *
	 * @var int Nanoseconds from the injected clock.
	 */
	private int $started;

	/**
	 * Start a fresh budget for one resolution, never shared across calls.
	 *
	 * @param Closure(): int $clock Monotonic clock returning nanoseconds.
	 * @return void
	 */
	public function __construct(private Closure $clock) {
		$this->started = ($this->clock)();
	}

	/**
	 * Refuse expired work before and after native calls; no timing guarantee during blocking I/O.
	 *
	 * @return void
	 * @throws RecipientResolutionException On elapsed limit or a non-monotonic clock.
	 */
	public function checkpoint(): void {
		$elapsed = ($this->clock)() - $this->started;
		if ($elapsed < 0 || $elapsed > self::MAX_DURATION_NS) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::limit();
		}
	}

	/**
	 * Count native expansion work, including structural or non-reader circle entries.
	 *
	 * @return void
	 * @throws RecipientResolutionException When the shared work or time budget is exhausted.
	 */
	public function work(): void {
		$this->checkpoint();
		if (++$this->visits > self::MAX_VISITS) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::limit();
		}
	}

	/**
	 * Add a resolved account using its UID, counting overlap work as well as distinct users.
	 *
	 * @param IUser $user Existing native account.
	 * @return void
	 * @throws RecipientResolutionException On limits, invalid identity/name or inconsistent duplicate names.
	 */
	public function add(IUser $user): void {
		$this->work();
		$uid = $user->getUID();
		$name = $user->getDisplayName();
		$name = $name === '' ? $uid : $name;
		if ($uid === '' || preg_match('//u', $uid) !== 1 || preg_match('//u', $name) !== 1) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
		}
		$member = ['uid' => $uid, 'name' => $name];
		$key = 'uid:' . $uid;
		if (isset($this->members[$key]) && $this->members[$key] !== $member) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
		}
		$this->members[$key] = $member;
		if (count($this->members) > self::MAX_RECIPIENTS) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::limit();
		}
		$this->checkpoint();
	}

	/**
	 * Finish a complete set in stable binary UID order.
	 *
	 * @return list<array{uid:string,name:string}> Full internal identities, never a hidden-resource aggregate.
	 */
	public function members(): array {
		$this->checkpoint();
		$members = array_values($this->members);
		usort($members, static fn (array $a, array $b): int => strcmp($a['uid'], $b['uid']));
		return $members;
	}
}
