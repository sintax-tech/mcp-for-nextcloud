<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Adds empty group-grant storage; no existing policy or credential is imported or changed. */
class Version001100Date20261014000000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output migration output
	 * @param Closure(): ISchemaWrapper $schemaClosure current schema
	 * @param array<string, mixed> $options migration options
	 * @return ISchemaWrapper|null the schema with the new table, or null when it already exists
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('mcp_group_grants')) {
			return null;
		}
		$table = $schema->createTable('mcp_group_grants');
		// Only the fixed-size digest is indexed; the original, opaque id is checked byte for byte on reads.
		$table->addColumn('group_key', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('group_id', Types::TEXT, ['notnull' => true]);
		$table->addColumn('module', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('operation', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->setPrimaryKey(['group_key', 'module', 'operation']);
		return $schema;
	}
}
