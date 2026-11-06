<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

/** Polls 9.x UTC/date interval and typed share signatures. */
final class Polls9Adapter {
	/** @param object $options Fresh native service. @param array<string,mixed> $r Validated input. @return void */
	public function updateOption(object $options, array $r): void {
		$o = $r['option'];
		$options->update($r['option_id'], $o['text'] ?? '',
			isset($o['timestamp']) ? new \OCA\Polls\Model\DateTimeImmutable('@' . $o['timestamp']) : null, new \OCA\Polls\Model\DateInterval('PT' . ($o['duration'] ?? 0) . 'S'));
	}
	/** @param object $shares Fresh native service. @param array<string,mixed> $r Validated input. @param object|null $user Typed recipient, absent for public. @return void */
	public function addShare(object $shares, array $r, ?object $user): void {
		if ($r['type'] === 'public') { $shares->createPublicShare($r['poll_id']); }
		else { $shares->createShare($r['poll_id'], $user, 'poll'); }
	}
}
