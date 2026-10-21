<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/** Read-only subnet discovery; Docker atomically rejects any concurrent allocation conflict. */
function command(array $args): string {
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot inspect host network');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($process) !== 0) throw new RuntimeException('Cannot inspect host network');
    return $output;
}
$routes = json_decode(command(['ip', '-j', '-4', 'route', 'show', 'table', 'all']), true, 512, JSON_THROW_ON_ERROR);
$occupied = [];
foreach ($routes as $route) {
    if (($route['dst'] ?? 'default') !== 'default') $occupied[] = $route['dst'];
}
$ids = preg_split('/\s+/', trim(command(['docker', 'network', 'ls', '-q'])), -1, PREG_SPLIT_NO_EMPTY);
foreach (json_decode(command(['docker', 'network', 'inspect', ...$ids]), true, 512, JSON_THROW_ON_ERROR) as $network) {
    foreach ($network['IPAM']['Config'] ?? [] as $config) if (isset($config['Subnet'])) $occupied[] = $config['Subnet'];
}
for ($attempt = 0; $attempt < 1024; ++$attempt) {
    $candidate = '10.' . random_int(200, 239) . '.' . random_int(0, 255) . '.0';
    $start = ip2long($candidate); $end = $start + 255;
    foreach ($occupied as $cidr) {
        [$address, $bits] = array_pad(explode('/', $cidr), 2, '32');
        $ip = ip2long($address);
        if ($ip === false) continue;
        $size = 2 ** (32 - (int)$bits); $first = (int)(floor($ip / $size) * $size);
        if ($start <= $first + $size - 1 && $end >= $first) continue 2;
    }
    echo $candidate, '/24', PHP_EOL;
    exit(0);
}
fwrite(STDERR, "No free private /24 subnet found; existing networks were not modified.\n");
exit(1);
