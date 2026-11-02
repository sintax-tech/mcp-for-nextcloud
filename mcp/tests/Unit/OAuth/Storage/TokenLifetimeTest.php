<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth\Storage;

use OCA\Mcp\OAuth\Storage\OAuthStore;
use OCA\Mcp\OAuth\Storage\TokenLifetime;
use OCA\Mcp\OAuth\Tokens\TokenService;
use OCA\Mcp\Tests\Unit\FakeDatabase;
use PHPUnit\Framework\TestCase;

/** Refresh issuance and persistence cleanup retain the released lifetime and exact expiry boundary. */
final class TokenLifetimeTest extends TestCase {
	/** @return void The public service alias still exposes precisely thirty days. */
	public function testPublicAliasAndStorageSourceRetainTheReleasedValue(): void {
		self::assertSame(2592000, TokenLifetime::REFRESH_TTL);
		self::assertSame(TokenLifetime::REFRESH_TTL, TokenService::REFRESH_TTL);
	}

	/** @return void Spent records expire at the same cutoff computed from the storage-owned lifetime. */
	public function testStorePurgesOnlySpentRecordsBeforeTheRefreshCutoff(): void {
		$db = new FakeDatabase($this, ['mcp_oauth_tokens' => [], 'mcp_oauth_spent' => [
			['id' => 1, 'expires_at' => 99], ['id' => 2, 'expires_at' => 100],
		]], []);
		$store = new OAuthStore($db->connection());
		$connection = $db->connection();
		$connection->beginTransaction();
		try {
			self::assertFalse($store->rotate(1, 'missing', 'access', 3700, 'refresh', 100 + TokenLifetime::REFRESH_TTL));
			$connection->commit();
		} catch (\Throwable $error) {
			$connection->rollBack();
			throw $error;
		}
		self::assertSame([2], array_column($db->tables['mcp_oauth_spent'], 'id'));
	}
	/** @return void Storage must read its own lifetime source without importing the upper OAuth service. */
	public function testStoreDoesNotDependOnTheTokenServiceLayer(): void {
		$source = file_get_contents((new \ReflectionClass(OAuthStore::class))->getFileName());
		foreach (token_get_all($source) as $token) {
			if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
				self::assertNotSame('TokenService', basename(str_replace('\\', '/', $token[1])));
			}
		}
	}

}
