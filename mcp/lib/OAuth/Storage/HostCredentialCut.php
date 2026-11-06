<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Storage;

use OCP\IDBConnection;

/** Cuts credentials by a parsed exact HTTPS host, never by LIKE or a suffix match; called under the issuance mutex. */
final class HostCredentialCut {
	/** @param IDBConnection $db Official policy transaction's connection. */
	public function __construct(private IDBConnection $db) {}

	/** @param string $host Exact lowercase withdrawn host. @return void Codes, grants and replay history are removed together. */
	public function delete(string $host): void {
		foreach (['mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_spent'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$key = $table === 'mcp_oauth_spent' ? 'refresh_hash' : 'id';
			$result = $qb->select($key, 'client_id')->from($table)->executeQuery();
			try {
				$rows = $result->fetchAll();
			} finally {
				$result->closeCursor();
			}
			foreach ($rows as $row) {
				$parts = parse_url($row['client_id']);
				if (is_array($parts) && ($parts['scheme'] ?? '') === 'https' && strtolower($parts['host'] ?? '') === $host) {
					$cut = $this->db->getQueryBuilder();
					$cut->delete($table)->where($cut->expr()->eq($key, $cut->createNamedParameter($row[$key])))->executeStatement();
				}
			}
		}
	}
}
