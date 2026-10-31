<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Creates the single policy state row, and only that row.
 *
 * A fresh install never runs a migration's postSchemaChange(): the server asks for schema only, because an app that
 * starts empty has nothing to migrate. The seed therefore also runs as a declared install and post-migration step,
 * both calling this one implementation, so install and upgrade leave the same state behind.
 *
 * The seed is a plain insert whose unique-key violation is the expected outcome when the row already exists, so
 * re-running it against a live instance, or racing another initialisation, keeps the revision already committed and
 * takes no lock beyond the insert itself (unlike the deprecated insertIfNotExist). Runtime code never seeds: a missing
 * row is a storage failure, not a default.
 */
final class PolicyStateSeeder {
	public function __construct(private IDBConnection $db) {}

	/** @throws Exception for any database failure other than the row already existing */
	public function seed(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(PolicyStateMapper::TABLE);
		foreach (['id' => 1, 'revision' => 0, 'lock_seq' => 0, 'auth_epoch' => 0] as $column => $value) {
			$qb->setValue($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT));
		}
		try {
			$qb->executeStatement();
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}
}