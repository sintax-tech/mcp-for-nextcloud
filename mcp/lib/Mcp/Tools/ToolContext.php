<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

/** Who calls a {@see Tool}: the authenticated user the registry already checked the grant of. */
final readonly class ToolContext {
	/**
	 * @param string $userId authenticated user id
	 */
	public function __construct(
		public string $userId,
	) {
	}
}
