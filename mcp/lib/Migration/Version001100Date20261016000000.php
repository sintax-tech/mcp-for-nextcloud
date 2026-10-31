<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The authoritative table of the administrator's OAuth clients (OAuthClientsMapper): one row per client, the native
 * client's switch included. Admission reads it from the database, never through the AppConfig caches.
 *
 * On an upgrade the switch 1.0.8 kept in the app configuration (oauth_native_client_enabled) is imported once, with
 * gen 1, and so is a list of self-hosted clients a pre-release build may have stored (oauth_custom_clients). Nothing
 * is imported over a row that already exists, and the legacy values stay where they are (they are no longer read).
 * No terminal, occ or SQL step is needed.
 */
class Version001100Date20261016000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db, private IConfig $config, private IAppConfig $appConfig) {}

	/**
	 * @param IOutput $output migration output
	 * @param Closure(): ISchemaWrapper $schemaClosure current schema
	 * @param array<string, mixed> $options migration options
	 * @return ISchemaWrapper|null the changed schema, or null when the table already exists
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(OAuthClientsMapper::TABLE)) {
			return null;
		}
		$table = $schema->createTable(OAuthClientsMapper::TABLE);
		$table->addColumn('client_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('enabled', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		// JSON list of the exact redirect URIs (each at most 2000 bytes, at most 20).
		$table->addColumn('redirect_uris', Types::TEXT, ['notnull' => false]);
		// Advances on every withdrawal; a consent page of an older generation can never issue a code.
		$table->addColumn('gen', Types::BIGINT, ['notnull' => true, 'default' => 1]);
		$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['client_id']);
		return $schema;
	}

	/**
	 * Imports the legacy settings once. A row already present is never overwritten.
	 *
	 * @param IOutput $output migration output
	 * @param Closure(): ISchemaWrapper $schemaClosure current schema
	 * @param array<string, mixed> $options migration options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$clients = new OAuthClientsMapper($this->db);
		$this->appConfig->clearCache(false);
		if ($this->config->getAppValue('mcp', NativeClient::CONFIG_KEY, '0') === '1' && $clients->find(NativeClient::CLIENT_ID) === null) {
			$clients->put(['clientId' => NativeClient::CLIENT_ID, 'kind' => OAuthClientsMapper::KIND_NATIVE, 'name' => NativeClient::NAME,
				'enabled' => true, 'redirectUris' => [], 'gen' => '1', 'position' => 0]);
			$output->info('Imported the native OAuth client switch (on)');
		}
		$legacy = json_decode($this->appConfig->getValueString('mcp', CustomClient::CLIENTS_KEY, '[]', true), true);
		foreach (is_array($legacy) ? array_values($legacy) : [] as $position => $entry) {
			if (!is_array($entry) || !is_string($entry['id'] ?? null) || preg_match('/^[0-9a-f]{12}$/D', $entry['id']) !== 1
				|| !is_string($entry['name'] ?? null) || $entry['name'] === '' || !is_bool($entry['enabled'] ?? null)) {
				continue;
			}
			try {
				$uris = CustomClient::validateUris($entry['redirectUris'] ?? null);
			} catch (\InvalidArgumentException) {
				continue;
			}
			$clientId = CustomClient::clientId($entry['id']);
			if ($uris === [] || $clients->find($clientId) !== null) {
				continue;
			}
			// A withdrawal may have happened under the old storage: the imported generation starts above it.
			$clients->put(['clientId' => $clientId, 'kind' => OAuthClientsMapper::KIND_CUSTOM, 'name' => $entry['name'],
				'enabled' => $entry['enabled'], 'redirectUris' => $uris, 'gen' => '2', 'position' => $position]);
		}
	}
}
