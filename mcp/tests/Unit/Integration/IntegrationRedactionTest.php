<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;

final class IntegrationRedactionTest extends TestCase {
    private function redact(string $credentials, string $log): array {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tests/Integration/ci/redact.php', $credentials, $log],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testMissingCredentialInputFailsWithoutPublishingLog(): void {
        $log = tempnam(sys_get_temp_dir(), 'mcp-redact-');
        try {
            file_put_contents($log, 'private diagnostic');
            [$status, $out] = $this->redact($log . '.missing', $log);
            self::assertNotSame(0, $status);
            self::assertSame('', $out);
        } finally { unlink($log); }
    }

    public function testRedactsCredentialFamiliesAndKeepsArtifactChecksums(): void {
        $log = tempnam(sys_get_temp_dir(), 'mcp-redact-');
        $credentials = tempnam(sys_get_temp_dir(), 'mcp-redact-');
        $secret = bin2hex(random_bytes(24));
        $basic = base64_encode('fixture:' . bin2hex(random_bytes(16)));
        $testName = 'testSwitchingTheServiceOffMovesTheGlobalEpochAndOnlyLaterCredentialsWork';
        $hash = hash('sha256', 'artifact');
        try {
            file_put_contents($credentials, 'ADMIN_PASSWORD=' . $secret . "\n");
            file_put_contents($log, "$secret\nncmcp_at_example\nAuthorization: Basic $basic\n" . str_repeat('z', 72) . "\n$hash\n$testName\n");
            [$status, $out, $err] = $this->redact($credentials, $log);
            self::assertSame(0, $status, $err);
            self::assertSame(str_repeat("[REDACTED]\n", 2) . "Authorization: [REDACTED]\n[REDACTED]\n$hash\n$testName\n", $out);
        } finally { unlink($log); unlink($credentials); }
    }

    public function testRedactsHeadersInPhpAndJsonDiagnostics(): void {
        $log = tempnam(sys_get_temp_dir(), 'mcp-redact-');
        $credentials = tempnam(sys_get_temp_dir(), 'mcp-redact-');
        $basic = base64_encode('fixture:' . bin2hex(random_bytes(16)));
        $cookie = bin2hex(random_bytes(16)); $csrf = base64_encode(random_bytes(32));
        try {
            file_put_contents($credentials, "ADMIN_PASSWORD=disposable-private\n");
            file_put_contents($log, "'Authorization' => 'Basic $basic'\n\"Cookie\": \"fixture=$cookie\"\n'requesttoken' => '$csrf'\n");
            [$status, $out, $err] = $this->redact($credentials, $log);
            self::assertSame(0, $status, $err);
            foreach ([$basic, $cookie, $csrf] as $secret) self::assertStringNotContainsString($secret, $out);
            foreach (['Authorization', 'Cookie', 'requesttoken'] as $header) self::assertStringContainsString($header, $out);
        } finally { unlink($log); unlink($credentials); }
    }

}
