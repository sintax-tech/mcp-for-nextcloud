<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

/** Selects the official share creation signature before any effect is attempted. */
final class PollsShareCreator {
	/**
	 * @param object $shares Fresh native service.
	 * @param array<string,mixed> $r Validated request.
	 * @param object|null $user Native typed recipient, absent for public.
	 * @param bool $nine Proven native version line.
	 * @return void
	 * @throws \Throwable If native creation fails; never retried.
	 */
	public static function create(object $shares, array $r, ?object $user, bool $nine): void {
		if (!$nine) {
			$v = $r['recipient'] ?? [];
			$shares->add(
				$r['poll_id'],
				$r['type'],
				$v['user_id'] ?? $v['group_id'] ?? $v['email_address'] ?? $v['contact_id'] ?? '',
				$v['display_name'] ?? '',
				$v['email_address'] ?? '',
				'poll'
			);
		} elseif ($r['type'] === 'public') {
			$shares->createPublicShare($r['poll_id']);
		} else {
			$shares->createShare($r['poll_id'], $user, 'poll');
		}
	}
}
