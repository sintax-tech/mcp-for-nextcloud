<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class LintTest extends TestCase {
    private string $dir;
    private const APP = __DIR__ . '/../../..';

    protected function setUp(): void {
        $this->dir = self::APP . '/build/lint test ' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array{int, string} */
    private function runLint(array $paths, ?string $script = null): array {
        $process = proc_open([PHP_BINARY, $script ?? self::APP . '/scripts/lint.php', ...$paths], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->dir);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    public function testChecksSyntaxWithoutExecutingFilesAndPreservesSpaces(): void {
        $file = $this->dir . '/valid file.php';
        file_put_contents($file, '<?php throw new RuntimeException("must not execute");');
        [$code, $output] = $this->runLint([$file]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('1 PHP file', $output);
    }

    public function testReportsInvalidFileAndFails(): void {
        $file = $this->dir . '/broken file.php';
        file_put_contents($file, '<?php function {');
        [$code, $output] = $this->runLint([$file]);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('broken file.php', $output);
    }

    public function testMissingAndEmptyPathsFail(): void {
        foreach ([$this->dir . '/missing.php', $this->dir] as $path) {
            [$code, $output] = $this->runLint([$path]);
            self::assertNotSame(0, $code);
            self::assertStringContainsString($path, $output);
        }
    }

    public function testDefaultScopeCatchesUntrackedSourceAndSkipsBuildVendorAndSymlinks(): void {
        foreach (['lib', 'appinfo', 'templates', 'scripts', 'tests', 'lib/vendor', 'tests/build', 'outside'] as $dir) {
            mkdir($this->dir . '/' . $dir, 0777, true);
        }
        self::assertFileExists(self::APP . '/scripts/lint.php');
        copy(self::APP . '/scripts/lint.php', $this->dir . '/scripts/lint.php');
        file_put_contents($this->dir . '/lib/valid.php', '<?php');
        foreach (['lib/vendor/broken.php', 'tests/build/broken.php', 'outside/broken.php'] as $file) {
            file_put_contents($this->dir . '/' . $file, '<?php function {');
        }
        symlink($this->dir . '/outside', $this->dir . '/lib/link');
        [$code, $output] = $this->runLint([], $this->dir . '/scripts/lint.php');
        self::assertSame(0, $code, $output);
        file_put_contents($this->dir . '/tests/new.php', '<?php function {');
        [$code, $output] = $this->runLint([], $this->dir . '/scripts/lint.php');
        self::assertNotSame(0, $code);
        self::assertStringContainsString('new.php', $output);
    }
}
