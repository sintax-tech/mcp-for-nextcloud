<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Reading;

/**
 * The mail_search_messages read, so a meeting port can call the search and no other mail operation.
 */
interface MailSearchListing {
	/**
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated actor
	 * @return array<string, mixed> the MCP result of mail_search_messages
	 */
	public function searchMessages(array $arguments, string $userId): array;
}
