<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Additive schema of the optional per-user resource scope (issue #4, contracts B.3). A mode row means the module is
 * restricted for the user; its item rows are the resources still allowed, possibly none. No row is seeded: an upgrade
 * leaves every user unrestricted, exactly as before, and a release without this feature ignores both tables.
 */
class Version001100Date20261015020000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output migration output
	 * @param Closure(): ISchemaWrapper $schemaClosure current schema
	 * @param array<string, mixed> $options migration options
	 * @return ISchemaWrapper|null the changed schema, or null when both tables already exist
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable(ResourceScopeMapper::MODES_TABLE)) {
			$table = $schema->createTable(ResourceScopeMapper::MODES_TABLE);
			$table->addColumn('user_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('module', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('user_id', Types::TEXT, ['notnull' => true]);
			// Revision of the policy commit that saved this scope, so a delayed account cleanup never removes a later one.
			$table->addColumn('policy_rev', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['user_key', 'module']);
			$changed = true;
		}
		if (!$schema->hasTable(ResourceScopeMapper::ITEMS_TABLE)) {
			$table = $schema->createTable(ResourceScopeMapper::ITEMS_TABLE);
			$table->addColumn('user_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('module', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('resource_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('resource_id', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['user_key', 'module', 'resource_key']);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
