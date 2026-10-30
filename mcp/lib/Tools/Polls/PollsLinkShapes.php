<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

/** Existing native direct participation URLs for an editor; never serializes rich shares or resolves containers. */
final class PollsLinkShapes {
	/** Fixed coverage, never a count of omitted audiences. */
	public const COVERAGE = 'native-direct-participation-links';
	/**
	 * Validates all received shares, retaining only source-proven direct participation types.
	 * @param array<mixed> $shares native list(pollId, 'poll') result, not a raw mapper query
	 * @param int $pollId authorized poll
	 * @param list<int> $groups native poll groups for inherited membership checks
	 * @param callable():void $assert checks context before each getter
	 * @return list<array{id:int,type:string,url:string,locked:bool}> eligible links
	 * @throws PollsRefusal for contradictory or unsupported data
	 */
	public function project(array $shares, int $pollId, array $groups, callable $assert): array {
		$out = [];
		$seen = [];
		$class = PollsContentSurface::SHARE;
		foreach ($shares as $share) {
			if (!$share instanceof $class) {
				throw PollsRefusal::unavailable();
			}
			$call = static function (string $method) use ($share, $assert): mixed {
				$assert();
				return match ($method) {
					'getId' => $share->getId(), 'getPollId' => $share->getPollId(), 'getGroupId' => $share->getGroupId(),
					'getDeleted' => $share->getDeleted(), 'getLocked' => $share->getLocked(),
					'getType' => $share->getType(), 'getURL' => $share->getURL(),
					default => throw PollsRefusal::unavailable(),
				};
			};
			$id = $call('getId');
			$parent = $call('getPollId');
			$group = $call('getGroupId');
			$deleted = $call('getDeleted');
			$locked = $call('getLocked');
			$type = $call('getType');
			if (!is_int($id) || $id < 1 || isset($seen[$id]) || !is_int($deleted) || !is_int($locked)
				|| !is_string($type) || !in_array($type, ['user', 'admin', 'group', 'public', 'email', 'contact', 'external', 'circle', 'contactGroup'], true)
				|| ($parent !== $pollId && !($parent === null && is_int($group) && in_array($group, $groups, true)))) {
				throw PollsRefusal::unavailable();
			}
			$seen[$id] = true;
			if ($deleted !== 0 || $parent === null || in_array($type, ['circle', 'contactGroup'], true)) {
				continue;
			}
			$url = $call('getURL');
			if (!is_string($url)) {
				throw PollsRefusal::unavailable();
			}
			if ($url === '') {
				continue;
			}
			$out[] = ['id' => $id, 'type' => $type, 'url' => PollsContentValues::url($url), 'locked' => $locked !== 0];
		}
		usort($out, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		return $out;
	}
}
