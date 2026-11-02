<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\Modules\Polls\Integration\PollsContentSurface;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Native vote projection; no raw UID accessor or directory enrichment is used. */
final class PollsVoteShapes {
	/**
	 * Receives anonymous identity reduction.
	 * @param PollsAnonymousIdentity $anonymous poll-bound HMAC projection
	 */
	public function __construct(private PollsAnonymousIdentity $anonymous) {
	}

	/**
	 * Materializes native user objects inside the logged-in context (including the 8.6 shape).
	 * Anonymous type is trustworthy only under that invariant: without login native getSafeType can say guest.
	 * @param array<mixed> $votes native service collection
	 * @param int $pollId authorized poll
	 * @param bool $all observed RESULTS permission, not inferred from collection size
	 * @param callable():void $assert checks both native identities/login/token before every materialization
	 * @return list<array<string,mixed>> complete reduced projections
	 * @throws PollsRefusal for unsupported/foreign data or foreign votes in own scope
	 */
	public function project(array $votes, int $pollId, bool $all, callable $assert): array {
		$out = [];
		$seen = [];
		$class = PollsContentSurface::VOTE;
		foreach ($votes as $vote) {
			if (!$vote instanceof $class) {
				throw PollsRefusal::unavailable();
			}
			$assert();
			if (!$all && $vote->getCurrentUserIsEntityUser() !== true) {
				throw PollsRefusal::unavailable();
			}
			$assert();
			$row = $vote->jsonSerialize();
			if (!is_array($row) || PollsContentValues::integer($row, 'pollId', 1) !== $pollId) {
				throw PollsRefusal::unavailable();
			}
			$id = PollsContentValues::integer($row, 'id', 1);
			$optionId = PollsContentValues::integer($row, 'optionId', 1);
			$key = $id . ':' . $optionId;
			if (isset($seen[$key])) {
				throw PollsRefusal::unavailable();
			}
			$seen[$key] = true;
			$user = $row['user'] ?? null;
			if ($user instanceof \JsonSerializable) {
				$assert();
				$user = $user->jsonSerialize();
			}
			if (!is_array($user)) {
				throw PollsRefusal::unavailable();
			}
			$type = PollsContentValues::string($user, 'type');
			$userId = PollsContentValues::string($user, 'id');
			if ($type === '' || $userId === '') {
				throw PollsRefusal::unavailable();
			}
			$identity = $type === constant(PollsContentSurface::ANON . '::TYPE') ? $this->anonymous->of($pollId, $userId)
				: ['id' => $userId, 'type' => $type, 'displayName' => PollsContentValues::string($user, 'displayName', true)];
			$out[] = ['id' => $id, 'pollId' => $pollId, 'optionId' => $optionId,
				'optionText' => PollsContentValues::string($row, 'optionText', true),
				'answer' => PollsContentValues::string($row, 'answer'), 'deleted' => PollsContentValues::integer($row, 'deleted'), 'user' => $identity];
		}
		usort($out, static fn (array $a, array $b): int => ($a['id'] <=> $b['id']) ?: ($a['optionId'] <=> $b['optionId']));
		return $out;
	}
}
