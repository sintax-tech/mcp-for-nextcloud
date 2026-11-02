<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
require dirname(__DIR__, 4) . '/bootstrap.php';
// An optional immutable source is loaded before autoload can resolve the current facade.
if (isset($argv[1])) { require $argv[1]; }
$rig = new \OCA\Mcp\Tests\Unit\Access\Policy\Transactions\ExecutionScenario('record');
$output = [];
foreach ($rig::CASES as $case) { $output[$case] = $rig->record($case); }
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
