<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Contracts;

/** Guarded native boundary; only sanitized scalar snapshots escape Integration. */
interface PollWriteNative extends PollWriteReader {
	/** @param string $uid Actor. @param array<string,mixed> $request Validated arguments. @param array<string,mixed> $before Guarded latest projection. @param \Closure $attempted Called immediately before the sink. @return void */
	public function mutate(string $uid, array $request, array $before, \Closure $attempted): void;
}
