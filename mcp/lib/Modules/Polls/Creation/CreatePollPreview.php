<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Creation;

/** The plan of the independently granted private creation; it never writes. */
final class CreatePollPreview {
	/**
	 * @param CreateFlow $flow preview and confirmed attempt
	 */
	public function __construct(private CreateFlow $flow) {
	}

	/**
	 * Describes without writing.
	 * @param array<string,mixed> $arguments input
	 * @param string $userId authenticated actor
	 * @return array<string,mixed> structured plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->flow->run($arguments,$userId,true);
	}
}
