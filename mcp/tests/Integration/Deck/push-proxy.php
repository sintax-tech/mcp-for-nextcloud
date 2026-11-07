<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Router of the throwaway push proxy `php -S 127.0.0.1:<port>` runs inside the test container (issue #26).
// It records what the Notifications app posts and answers with the status the test wrote; nothing leaves the container.
$state = getenv('MCP_IT_PUSH_STATE') ?: '/tmp/mcp-it-push';
$body = (string)file_get_contents('php://input');
file_put_contents($state . '-requests.jsonl', json_encode(['method' => $_SERVER['REQUEST_METHOD'] ?? '',
	'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), 'bytes' => strlen($body),
	'at' => microtime(true)], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
$status = (int)(@file_get_contents($state . '-status') ?: 200);
http_response_code($status);
header('Content-Type: application/json');
echo $status === 200 ? '{"rejectedDevices":[],"failed":0}' : '{"error":"synthetic"}';
