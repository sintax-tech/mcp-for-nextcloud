<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

/** Polls 8.6 public option/share signatures; no fallback after a native call. */
final class Polls86Adapter {
	/** @param object $options Fresh native service. @param array<string,mixed> $r Validated input. @return void */
	public function updateOption(object $options, array $r): void {
		$o = $r['option'];
		$options->update($r['option_id'], $o['timestamp'] ?? 0, $o['text'] ?? '', $o['duration'] ?? 0);
	}
	/** @param object $shares Fresh native service. @param array<string,mixed> $r Validated input. @param object|null $user Typed recipient, absent for public. @return void */
	public function addShare(object $shares, array $r, ?object $user): void {
		$v = $r['recipient'] ?? [];
		$shares->add($r['poll_id'], $r['type'], $v['user_id'] ?? $v['group_id'] ?? $v['email_address'] ?? $v['contact_id'] ?? '',
			$v['display_name'] ?? '', $v['email_address'] ?? '', 'poll');
	}
}
