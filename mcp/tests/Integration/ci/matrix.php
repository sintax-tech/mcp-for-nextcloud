<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

$manifest = json_decode(file_get_contents(__DIR__ . '/matrix.json'), true, 512, JSON_THROW_ON_ERROR);
$plans = [];
foreach ($manifest['cells'] as $cell) {
    $modes = ['fresh', 'upgrade'];
    if ($cell['nc'] === '35' && $cell['db'] === 'mariadb') {
        $modes = [...$modes, 'upgrade-local', 'upgrade-upstream', 'upgrade-upstream-111', 'replace-upstream'];
    }
    foreach ($modes as $mode) {
        $plans[] = $cell + ['mode' => $mode, 'baseline_commit' => $manifest['baselines'][$mode] ?? null,
            'publishes_ports' => false, 'disposable' => true];
    }
}
if (array_slice($argv, 1) === ['--matrix']) {
    echo json_encode(['include' => $plans], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}
foreach ($plans as $plan) {
    if (array_slice($argv, 1) === [$plan['nc'], $plan['db'], $plan['mode']]) {
        echo json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
        exit(0);
    }
}
fwrite(STDERR, "Expected a supported Nextcloud/database/mode cell; use matrix.php --matrix to list cells.\n");
exit(2);
