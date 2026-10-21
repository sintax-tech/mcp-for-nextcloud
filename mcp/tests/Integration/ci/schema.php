<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
define('OC_CONSOLE', 1);
require_once __DIR__ . '/../Support/ProbeCompletion.php';
$probeCompletion = new \OCA\Mcp\Tests\Integration\Support\ProbeCompletion();
require_once '/var/www/html/lib/base.php';
$db = \OCP\Server::get(\OCP\IDBConnection::class);
$qb = $db->getQueryBuilder();
$rows = $qb->select('version')->from('migrations')->where($qb->expr()->eq('app', $qb->createNamedParameter('mcp')))
    ->orderBy('version')->executeQuery()->fetchAll();
if ($rows === []) throw new RuntimeException('Installed MCP has no migration history');
echo json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;

$probeCompletion->complete();
