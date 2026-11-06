<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Contracts;

/** Read-only native context used by plans; no effect method is exposed on this port. */
interface PollWriteReader {
	/**
	 * @param string $uid Actor.
	 * @param string $operation Exact grant.
	 * @return void
	 * @throws \OCA\Mcp\Modules\Polls\PollsRefusal If identity or admission fails.
	 */
	public function open(string $uid, string $operation): void;
	/**
	 * @param string $uid Actor.
	 * @param array<string,mixed> $request Validated request.
	 * @return array<string,mixed> Newly authorized scalar observation.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If the target cannot be observed.
	 */
	public function snapshot(string $uid, array $request): array;
	/**
	 * @return void
	 * @throws \Throwable If the native identity cache cannot be cleared.
	 */
	public function close(): void;
}
