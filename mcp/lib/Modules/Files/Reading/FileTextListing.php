<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

/**
 * The files_read read, so a meeting port can call it without a second copy of its scope and alias rules.
 */
interface FileTextListing {
	/**
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string, mixed> the MCP result of files_read
	 */
	public function handle(array $arguments, string $userId): array;
}
