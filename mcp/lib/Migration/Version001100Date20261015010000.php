<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Additive schema for per-user OAuth generations, recheck causes, group identity lifecycle and policy events, plus
 * the generation columns on the OAuth tables. Nothing is seeded: an absent mcp_auth_users row means issue_seq 1 and
 * revoked_below 0, and every existing code, token and spent record takes the defaults (epoch 0, generation 1), so an
 * upgrade keeps every credential valid. No row is rewritten and no hash changes.
 */
class Version001100Date20261015010000 extends SimpleMigrationStep {
	/** OAuth tables that gain (auth_global_epoch, auth_gen). */
	public const OAUTH_TABLES = ['mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_spent'];

	/**
	 * @param IOutput $output migration output
	 * @param Closure(): ISchemaWrapper $schemaClosure current schema
	 * @param array<string, mixed> $options migration options
	 * @return ISchemaWrapper|null the changed schema, or null when everything already exists
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable(AuthUsersMapper::TABLE)) {
			$table = $schema->createTable(AuthUsersMapper::TABLE);
			$table->addColumn('user_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('user_id', Types::TEXT, ['notnull' => true]);
			// Last generation issued, and every generation up to revoked_below is invalid. Tombstones stay forever.
			$table->addColumn('issue_seq', Types::BIGINT, ['notnull' => true, 'default' => 1]);
			$table->addColumn('revoked_below', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['user_key']);
			$changed = true;
		}
		if (!$schema->hasTable(PolicyEventsMapper::TABLE)) {
			$table = $schema->createTable(PolicyEventsMapper::TABLE);
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 24]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['status', 'created_at'], 'mcp_pol_ev_status');
			$changed = true;
		}
		if (!$schema->hasTable(RecheckMapper::TABLE)) {
			$table = $schema->createTable(RecheckMapper::TABLE);
			$table->addColumn('user_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('cause_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('user_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('group_id', Types::TEXT, ['notnull' => false]);
			$table->addColumn('gen', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['user_key', 'cause_key']);
			$changed = true;
		}
		if (!$schema->hasTable(GroupLifeMapper::TABLE)) {
			$table = $schema->createTable(GroupLifeMapper::TABLE);
			$table->addColumn('group_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('group_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('gen', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('policy_rev', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['group_key']);
			$changed = true;
		}
		foreach (self::OAUTH_TABLES as $name) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			if (!$table->hasColumn('auth_global_epoch')) {
				$table->addColumn('auth_global_epoch', Types::BIGINT, ['notnull' => true, 'default' => 0]);
				$changed = true;
			}
			if (!$table->hasColumn('auth_gen')) {
				$table->addColumn('auth_gen', Types::BIGINT, ['notnull' => true, 'default' => 1]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
