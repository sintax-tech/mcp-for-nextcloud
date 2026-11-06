<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use OCA\Mcp\Db\CimdHostsSeeder;
use OCP\Migration\{IOutput, IRepairStep};

/** Fresh installs run schema-only migrations: the declared install step seeds host trust after the table exists. */
final class CimdHostsSeedStep implements IRepairStep {
	/** @param CimdHostsSeeder $seeder Shared idempotent import, also used by the upgrade migration. */
	public function __construct(private CimdHostsSeeder $seeder) {}

	/** @return string Native repair step label. */
	public function getName(): string { return 'Seed the MCP CIMD host trust'; }

	/** @param IOutput $output Native output. @return void Existing rows and tombstones remain untouched. */
	public function run(IOutput $output): void { $this->seeder->seed(); }
}
