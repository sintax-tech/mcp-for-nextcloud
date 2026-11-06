<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Migration\{Version000500Date20260930000000, Version000800Date20261002010000};
use OCA\Mcp\OAuth\Storage\HostCredentialCut;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/** Exact parsed host matching runs through production SQL on SQLite, including codes, grants and spent history. */
final class HostCredentialCutTest extends TestCase {
	/** @return void Suffixes, paths and userinfo never impersonate a withdrawn host; case of the host is normalized. */
	public function testOnlyTheExactHttpsHostIsDeletedFromEveryCredentialTable(): void {
		$db = new SqliteDatabase($this, [new Version000500Date20260930000000(), new Version000800Date20261002010000()]);
		$ids = ['https://claude.ai/a', 'https://CLAUDE.AI/b', 'https://sub.claude.ai/a', 'https://claude.ai.evil/a',
			'https://evil.test/claude.ai', 'https://claude.ai@evil.test/a', 'http://claude.ai/a', 'nextcloud-mcp-native'];
		foreach ($ids as $i => $client) {
			$db->dbal->insert('mcp_oauth_codes', ['code_hash' => 'c' . $i, 'user_id' => 'u', 'client_id' => $client,
				'redirect_uri' => 'https://callback.test', 'code_challenge' => 'challenge', 'resource' => 'resource', 'scope' => 'mcp', 'expires_at' => 100]);
			$db->dbal->insert('mcp_oauth_tokens', ['user_id' => 'u', 'client_id' => $client, 'resource' => 'resource', 'scope' => 'mcp',
				'access_hash' => 'a' . $i, 'access_expires' => 100, 'refresh_hash' => 'r' . $i, 'refresh_expires' => 100, 'created_at' => 0]);
			$db->dbal->insert('mcp_oauth_spent', ['refresh_hash' => 's' . $i, 'user_id' => 'u', 'client_id' => $client, 'grant_id' => $i, 'expires_at' => 100]);
		}
		$cut = new HostCredentialCut($db->connection());
		$cut->delete('claude.ai');
		$cut->delete('claude.ai');
		foreach (['mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_spent'] as $table) {
			self::assertSame(array_slice($ids, 2), $db->dbal->fetchFirstColumn('SELECT client_id FROM ' . $table));
		}
	}
}
