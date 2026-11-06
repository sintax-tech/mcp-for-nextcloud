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
			isset($o['timestamp']) ? PollsOptionValues::date($o['timestamp']) : null, PollsOptionValues::interval($o['duration'] ?? 0));
	}
	/** @param object $shares Fresh native service. @param array<string,mixed> $r Validated input. @param object|null $user Typed recipient, absent for public. @return void */
	public function addShare(object $shares, array $r, ?object $user): void {
		PollsShareCreator::create($shares, $r, $user, true);
	}
}
