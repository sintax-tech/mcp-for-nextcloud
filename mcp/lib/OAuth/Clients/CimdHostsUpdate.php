<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Clients;

use OCA\Mcp\Db\CimdHostsMapper;
use OCA\Mcp\OAuth\Storage\OAuthStore;
use OCA\Mcp\Platform\Numbers\DecimalCounter;

/** Participant of the existing policy transaction: host tombstones and exact-host credential withdrawal commit together. */
final class CimdHostsUpdate {
	/** @param CimdHostsMapper $hosts Official host state. @param OAuthStore $credentials Same transaction's OAuth store. */
	public function __construct(private CimdHostsMapper $hosts, private OAuthStore $credentials) {}

	/** @param list<string> $next Validated lowercase hosts. @return bool Whether authoritative admission changed. */
	public function apply(array $next): bool {
		$changed = false;
		foreach ($this->hosts->all() as $row) {
			$enabled = in_array($row['host'], $next, true);
			if ($enabled !== $row['enabled']) {
				$this->hosts->put($row['host'], $enabled, $enabled ? $row['gen'] : DecimalCounter::increment($row['gen']));
				if (!$enabled) {
					$this->credentials->deleteForHost($row['host']);
				}
				$changed = true;
			}
		}
		foreach ($next as $host) {
			if ($this->hosts->find($host) === null) {
				// Legacy credentials of hosts absent at migration have stamp 1 but were not trusted.
				$this->hosts->put($host, true, '2');
				$this->credentials->deleteForHost($host);
				$changed = true;
			}
		}
		return $changed;
	}
}
