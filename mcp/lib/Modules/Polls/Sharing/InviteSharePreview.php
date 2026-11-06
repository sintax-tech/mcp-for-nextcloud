<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Sharing;

use OCA\Mcp\Modules\Polls\Writing\PollWriteFlow;

/** Effect-free preview of polls_invite_share; shared guards remain in the mutation flow. */
final class InviteSharePreview {
	/** @param PollWriteFlow $flow Fresh guarded mutation flow. */
	public function __construct(private PollWriteFlow $flow) {}
	/** @param array<string,mixed> $arguments Tool arguments. @param string $uid Authenticated actor. @return array<string,mixed> Complete signed plan. */
	public function plan(array $arguments, string $uid): array {
		return $this->flow->run('polls_invite_share', $arguments, $uid, true);
	}
}
