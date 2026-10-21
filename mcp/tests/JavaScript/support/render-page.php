<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Test-only asset capture: the real template remains the single source of markup.
namespace OCP {
    final class Util {
        public static array $scripts = [];
        public static array $styles = [];
        public static function addScript(string $app, string $file): void { self::$scripts[] = $file; }
        public static function addStyle(string $app, string $file): void { self::$styles[] = $file; }
    }
}
namespace {
    function p(mixed $text): void { echo htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function print_unescaped(mixed $text): void { echo (string)$text; }
    $page = $argv[1] ?? '';
    if (!in_array($page, ['admin', 'personal'], true)) {
        fwrite(STDERR, "Expected admin or personal fixture.\n");
        exit(1);
    }
    $l = new class {
        public function t(string $text, array $params = []): string { return $params === [] ? $text : vsprintf($text, $params); }
    };
    $_ = [
        'endpoint' => 'http://127.0.0.1:18461/apps/mcp/mcp', 'serviceEnabled' => true,
        'ocrActive' => false, 'ocrUrl' => 'https://apps.nextcloud.com/apps/workflow_ocr',
        'version' => 'test', 'eligibleUsers' => 2, 'connectedUsers' => 1, 'activeConnections' => 2,
        'unsupportedApps' => [], 'logsAvailable' => true, 'logType' => 'file',
        'global' => true, 'eligible' => true, 'connected' => true,
        'action' => '/apps/mcp/settings/personal', 'token' => 'synthetic-request-token',
    ];
    ob_start();
    require dirname(__DIR__, 3) . '/templates/' . $page . '.php';
    $body = ob_get_clean();
    echo '<!doctype html><html lang="en" data-locale="en"><head><meta charset="utf-8"><title>MCP settings fixture</title>';
    foreach (\OCP\Util::$styles as $style) echo '<link rel="stylesheet" href="/apps/mcp/css/' . $style . '.css">';
    echo '</head><body>' . $body;
    foreach (\OCP\Util::$scripts as $script) echo '<script src="/apps/mcp/js/' . $script . '.js"></script>';
    echo '</body></html>';
}
