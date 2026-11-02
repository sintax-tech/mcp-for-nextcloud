<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Reading;

use OCA\Mcp\Modules\Polls\Integration\PollsCalls;
use OCA\Mcp\Modules\Polls\Integration\PollsExpiration;
use OCA\Mcp\Modules\Polls\Integration\PollsServices;
use OCA\Mcp\Modules\Polls\Integration\PollsSessionGuard;
use OCA\Mcp\Modules\Polls\Integration\PollsSurface;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Reobtains ACCESS and effective policy without serializing rich poll identities or counters. */
final class PollsContentPolicy {
	/**
	 * Receives the existing native resolver and identity guard.
	 * @param PollsServices $services native dependencies
	 * @param PollsSessionGuard $guard native identity checks
	 */
	public function __construct(private PollsServices $services, private PollsSessionGuard $guard) {
	}

	/**
	 * Reads a fresh poll, then checks each getter under the same authenticated global context.
	 * @param int $id poll id
	 * @param string $uid actor
	 * @param object $session native session
	 * @param bool $links requires native EDIT when true
	 * @return array<string,mixed> private policy binding, never returned to the caller
	 * @throws PollsRefusal for absent/prohibited/malformed policy
	 */
	public function read(int $id, string $uid, object $session, bool $links): array {
		$this->guard->native($session, $uid);
		try {
			$service = $this->services->resolve(PollsSurface::POLLS);
			$this->guard->native($session, $uid);
			$poll = $service->get($id);
		} catch (\Throwable $error) {
			if (PollsCalls::missing($error)) {
				throw PollsRefusal::missing();
			}
			throw $error;
		}
		$class = PollsSurface::POLL;
		if (!$poll instanceof $class) {
			throw PollsRefusal::unavailable();
		}
		$call = function (string $method, mixed ...$args) use ($poll, $uid, $session): mixed {
			$this->guard->native($session, $uid);
			return match ($method) {
				'getId' => $poll->getId(),
				'getIsAllowed' => $poll->getIsAllowed($args[0]),
				'getAnonymous' => $poll->getAnonymous(),
				'getExpire' => $poll->getExpire(),
				'getHideBookedUp' => $poll->getHideBookedUp(),
				'getOptionLimit' => $poll->getOptionLimit(),
				'getVoteLimit' => $poll->getVoteLimit(),
				'getDeleted' => $poll->getDeleted(),
				'getShowResults' => $poll->getShowResults(),
				'getPollGroups' => $poll->getPollGroups(),
				default => throw PollsRefusal::unavailable(),
			};
		};
		if ($call('getId') !== $id || $call('getIsAllowed', constant($class . '::PERMISSION_POLL_ACCESS')) !== true) {
			throw PollsRefusal::missing();
		}
		$out = ['results' => $call('getIsAllowed', constant($class . '::PERMISSION_POLL_RESULTS_VIEW')),
			'edit' => $call('getIsAllowed', constant($class . '::PERMISSION_POLL_EDIT'))];
		if (!is_bool($out['results']) || !is_bool($out['edit'])) {
			throw PollsRefusal::unavailable();
		}
		if ($links && !$out['edit']) {
			throw PollsRefusal::missing();
		}
		foreach (['anonymous', 'expire', 'hideBookedUp', 'optionLimit', 'voteLimit', 'deleted'] as $field) {
			$value = $call('get' . ucfirst($field));
			if (!is_int($value)) {
				throw PollsRefusal::unavailable();
			}
			$out[$field] = $value;
		}
		$out['showResults'] = $call('getShowResults');
		$out['expired'] = PollsExpiration::read($poll, $this->guard, $session, $uid);
		$groups = $call('getPollGroups');
		if (!is_string($out['showResults']) || !mb_check_encoding($out['showResults'], 'UTF-8') || !is_array($groups)) {
			throw PollsRefusal::unavailable();
		}
		foreach ($groups as $group) {
			if (!is_int($group) || $group < 1) {
				throw PollsRefusal::unavailable();
			}
		}
		sort($groups, SORT_NUMERIC);
		$out['groups'] = array_values(array_unique($groups));
		return $out;
	}
}
