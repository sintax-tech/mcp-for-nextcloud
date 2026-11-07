<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Native additive schema and insert-only seed; runtime never reconstructs this row. */
class Version001100Date20261015000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(PolicyStateMapper::TABLE)) {
			return null;
		}
		$table = $schema->createTable(PolicyStateMapper::TABLE);
		$table->addColumn('id', Types::INTEGER, ['notnull' => true]);
		foreach (['revision', 'lock_seq', 'auth_epoch'] as $column) {
			$table->addColumn($column, Types::BIGINT, ['notnull' => true, 'default' => 0]);
		}
		$table->setPrimaryKey(['id']);
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// An upgrade comes through here; a fresh install gets the same seed from the declared install step, because the
		// server runs schema only when it installs an app that starts empty.
		(new PolicyStateSeeder($this->db))->seed();
	}
}
