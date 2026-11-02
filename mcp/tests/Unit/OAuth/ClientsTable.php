<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\Migration\Version001100Date20261016000000;
use OCA\Mcp\OAuth\Clients\CustomClient;
use OCA\Mcp\OAuth\Clients\NativeClient;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The authoritative table of the administrator's OAuth clients, in an SQLite database built by its own migration, and
 * the real mapper over it. A test sets the state as another process would (rows written directly), so what the code
 * under test reads is the database, never a cache.
 */
final class ClientsTable {
	public readonly SqliteDatabase $db;
	public readonly OAuthClientsMapper $mapper;

	public function __construct(TestCase $test) {
		$mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
		$this->db = new SqliteDatabase($test, [new Version001100Date20261016000000($mock(IDBConnection::class), $mock(IConfig::class), $mock(IAppConfig::class))]);
		$this->mapper = new OAuthClientsMapper($this->db->connection());
	}

	/** Turns the native client on or off, as stored, with the given generation. */
	public function native(bool $enabled, string $gen = '1'): void {
		$this->db->dbal->delete(OAuthClientsMapper::TABLE, ['client_id' => NativeClient::CLIENT_ID]);
		$this->db->dbal->insert(OAuthClientsMapper::TABLE, ['client_id' => NativeClient::CLIENT_ID, 'kind' => OAuthClientsMapper::KIND_NATIVE,
			'name' => NativeClient::NAME, 'enabled' => $enabled ? 1 : 0, 'redirect_uris' => '[]', 'gen' => $gen, 'position' => 0]);
	}

	/**
	 * Replaces every self-hosted client, as stored.
	 *
	 * @param list<array{id:string, name:string, enabled:bool, redirectUris:list<string>, gen?:string}> $clients
	 */
	public function custom(array $clients): void {
		$this->db->dbal->delete(OAuthClientsMapper::TABLE, ['kind' => OAuthClientsMapper::KIND_CUSTOM]);
		foreach ($clients as $position => $client) {
			$this->db->dbal->insert(OAuthClientsMapper::TABLE, ['client_id' => CustomClient::clientId($client['id']),
				'kind' => OAuthClientsMapper::KIND_CUSTOM, 'name' => $client['name'], 'enabled' => $client['enabled'] ? 1 : 0,
				'redirect_uris' => json_encode($client['redirectUris']), 'gen' => $client['gen'] ?? '1', 'position' => $position]);
		}
	}

	/** @return list<array<string, mixed>> the stored self-hosted clients, as the admin page lists them (with gen) */
	public function stored(): array {
		return CustomClient::clients($this->mapper);
	}

	/** @return bool|null the native client's switch as stored, null without a row */
	public function nativeRow(): ?bool {
		return $this->mapper->find(NativeClient::CLIENT_ID)['enabled'] ?? null;
	}
}
