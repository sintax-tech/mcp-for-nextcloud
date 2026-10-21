<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
if ($argc !== 3 || !is_readable($argv[1]) || !is_readable($argv[2])) {
    fwrite(STDERR, "Redaction requires readable credential and log inputs.\n");
    exit(1);
}
$text = file_get_contents($argv[2]);
$credentials = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($text === false || $credentials === false || $credentials === []) {
    fwrite(STDERR, "Redaction inputs could not be read.\n");
    exit(1);
}
foreach ($credentials as $line) {
    if (!str_contains($line, '=') || substr($line, strpos($line, '=') + 1) === '') {
        fwrite(STDERR, "Invalid redaction credential input.\n");
        exit(1);
    }
    [, $secret] = explode('=', $line, 2);
    $text = str_replace($secret, '[REDACTED]', $text);
}
// Native app passwords have 72 characters. Preserve only actual declared test identifiers,
// including their JUnit names, so redaction cannot conceal which assertion failed.
$testNames = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $source) {
    if (!$source->isFile() || !str_ends_with($source->getFilename(), 'Test.php')) continue;
    preg_match_all('/function (test[A-Za-z0-9_]+)\(/', file_get_contents($source->getPathname()), $matches);
    foreach ($matches[1] as $name) $testNames[$name] = true;
}
$text = preg_replace('/ncmcp_(?:at|rt|code)_[A-Za-z0-9_-]+/', '[REDACTED]', $text);
$text = preg_replace_callback('/(?<![A-Za-z0-9])[A-Za-z0-9]{72}(?![A-Za-z0-9])/',
    static fn (array $match): string => isset($testNames[$match[0]]) ? $match[0] : '[REDACTED]', $text);
$text = preg_replace('/(Authorization["\\\' :=>]+)(?:Basic|Bearer)\s+[^\s"\\\']+/i', '$1[REDACTED]', $text);
$text = preg_replace('/\b((?:Set-Cookie|Cookie|requesttoken)["\\\' :=>]+)[^\r\n"\\\']+/i', '$1[REDACTED]', $text);
echo $text;
