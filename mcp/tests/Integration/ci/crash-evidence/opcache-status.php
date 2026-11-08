<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Prints the OPcache and JIT state of the Apache workers as one JSON line. crash-evidence.sh copies it into the
 * disposable container for a single request at collect time and removes it right after; it is never part of the app.
 * OPcache keeps one shared memory segment and one JIT buffer for all workers, so any worker answers for all of them.
 */
$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
if ($status === false) {
	echo json_encode(['opcache' => 'unavailable']), "\n";
	return;
}
$statistics = $status['opcache_statistics'] ?? [];
echo json_encode([
	'php' => PHP_VERSION,
	'jit_setting' => ini_get('opcache.jit'),
	'memory' => $status['memory_usage'] ?? null,
	'interned_strings' => $status['interned_strings_usage'] ?? null,
	'statistics' => array_intersect_key($statistics, array_flip([
		'num_cached_scripts', 'max_cached_keys', 'hits', 'misses', 'opcache_hit_rate',
		'oom_restarts', 'hash_restarts', 'manual_restarts',
	])),
	'restart_pending' => $status['restart_pending'] ?? null,
	'restart_in_progress' => $status['restart_in_progress'] ?? null,
	'jit' => $status['jit'] ?? null,
], JSON_UNESCAPED_SLASHES), "\n";
