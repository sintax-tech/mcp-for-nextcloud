<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use OCA\Mcp\Db\PolicyStateSeeder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * The seed of the policy state row as a declared install and post-migration step.
 *
 * Registered in appinfo/info.xml, because the server is what runs it: on a fresh install it runs after the schema
 * exists, and on an upgrade of an instance installed before the state table the post-migration step runs too. Both
 * reach the same insert-if-absent the migration's postSchemaChange uses.
 */
final class PolicyStateSeedStep implements IRepairStep {
	public function __construct(private PolicyStateSeeder $seeder) {}

	public function getName(): string {
		return 'Seed the MCP policy state';
	}

	public function run(IOutput $output): void {
		$this->seeder->seed();
	}
}
