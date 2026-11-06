<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Db\CimdHostsMapper;
use OCA\Mcp\OAuth\Clients\ClientMetadataFetcher;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Persists CIMD host trust and credential generations without changing the effective allowlist on upgrade. */
class Version001205Date20261106000000 extends SimpleMigrationStep {
	/** @param IDBConnection $db Official connection. @param IConfig $config Existing hosts. @param IAppConfig $apps Cache barrier. */
	public function __construct(private IDBConnection $db, private IConfig $config, private IAppConfig $apps) {}

	/** @param IOutput $output Output. @param Closure $schemaClosure Schema provider. @param array $options Options. @return ISchemaWrapper|null Changed schema. */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable(CimdHostsMapper::TABLE)) {
			$table = $schema->createTable(CimdHostsMapper::TABLE);
			$table->addColumn('host_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('host', Types::TEXT, ['notnull' => true]);
			$table->addColumn('enabled', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('gen', Types::BIGINT, ['notnull' => true, 'default' => 1]);
			$table->setPrimaryKey(['host_key']);
			$changed = true;
		}
		foreach (['mcp_oauth_codes', 'mcp_oauth_tokens'] as $name) {
			if ($schema->hasTable($name) && !$schema->getTable($name)->hasColumn('client_gen')) {
				$schema->getTable($name)->addColumn('client_gen', Types::BIGINT, ['notnull' => true, 'default' => 1]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}

	/** @param IOutput $output Output. @param Closure $schemaClosure Schema provider. @param array $options Options. @return void Seeds only absent rows. */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->apps->clearCache(false);
		$value = $this->config->getAppValue('mcp', ClientMetadataFetcher::HOSTS_KEY, ClientMetadataFetcher::DEFAULT_HOSTS);
		$hosts = array_filter(array_map('trim', explode(',', strtolower($value))));
		$hosts = $hosts === [] ? explode(',', ClientMetadataFetcher::DEFAULT_HOSTS) : array_values(array_unique($hosts));
		$mapper = new CimdHostsMapper($this->db);
		foreach ($hosts as $host) {
			if ($mapper->find($host) === null) {
				$mapper->put($host, true, '1');
			}
		}
	}
}
