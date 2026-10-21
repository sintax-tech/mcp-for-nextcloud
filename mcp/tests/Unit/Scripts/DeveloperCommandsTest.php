<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeveloperCommandsTest extends TestCase {
    private const APP = __DIR__ . '/../../..';
    private string $dir;

    protected function setUp(): void {
        $this->dir = self::APP . '/build/command test ' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/ProbeTest.php', <<<'PHP'
<?php
final class ProbeTest extends \PHPUnit\Framework\TestCase {
    public function testLimits(): void {
        foreach (['memory_limit', 'post_max_size', 'upload_max_filesize'] as $key) {
            self::assertSame('1G', ini_get($key), $key);
        }
    }
    public function testPassing(): void { self::assertTrue(true); }
    public function testFailure(): void { self::fail('Intentional command failure'); }
}
PHP);
    }

    protected function tearDown(): void {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array{int, string} */
    private function runTest(string $filter, bool $fromRoot = false): array {
        $args = ['composer'];
        if ($fromRoot) {
            $args[] = '--working-dir=mcp';
        }
        array_push($args, 'test', '--', '--no-configuration', '--do-not-cache-result', '--filter', $filter, $this->dir . '/ProbeTest.php');
        $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $fromRoot ? self::APP . '/..' : self::APP);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    public function testForwardsQuotedFiltersAndAppliesLimitsFromBothDirectories(): void {
        foreach ([false, true] as $fromRoot) {
            [$code, $output] = $this->runTest('testLimits|testPassing', $fromRoot);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('OK (2 tests, 4 assertions)', $output);
        }
    }

    public function testEmptySelectionFails(): void {
        [$code, $output] = $this->runTest('NoSuchTest');
        self::assertNotSame(0, $code);
        self::assertStringContainsString('No tests executed', $output);
    }

    public function testFailedTestPropagatesFailure(): void {
        [$code, $output] = $this->runTest('testFailure');
        self::assertNotSame(0, $code);
        self::assertStringContainsString('Intentional command failure', $output);
    }

    public function testCompleteGateKeepsItsScopeAndStopsAfterFirstFailure(): void {
        $source = json_decode(file_get_contents(self::APP . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest = ['name' => 'mcp/command-probe', 'description' => 'Verification command probe',
            'license' => 'AGPL-3.0-or-later', 'scripts' => $source['scripts']];
        foreach (['docs:check', 'docs:translations', 'test:docs', 'test:clients:unit', 'lint', 'analyse:lib', 'analyse:core', 'assets:build', 'test', 'test:js', 'package'] as $leaf) {
            $manifest['scripts'][$leaf] = '@php record.php ' . $leaf;
        }
        file_put_contents($this->dir . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($this->dir . '/record.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/calls', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
exit(trim(file_get_contents(__DIR__ . '/fail')) === $argv[1] ? 23 : 0);
PHP);
        foreach (['docs:check', 'docs:translations', 'test:docs', 'test:clients:unit', 'lint', 'none'] as $fail) {
            file_put_contents($this->dir . '/calls', '');
            file_put_contents($this->dir . '/fail', $fail);
            $process = proc_open(['composer', 'check', '--', '--filter', 'NoSuchTest'],
                [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->dir);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($process);
            $calls = array_map(static fn ($line) => json_decode($line, true),
                file($this->dir . '/calls', FILE_IGNORE_NEW_LINES));
            $expected = [['docs:check'], ['docs:translations'], ['test:docs'], ['test:clients:unit'], ['lint'], ['analyse:lib'], ['analyse:core'], ['test'], ['test:js'], ['package']];
            if ($fail !== 'none') {
                self::assertNotSame(0, $code);
                $stop = array_search([$fail], $expected, true);
                self::assertNotFalse($stop);
                self::assertSame(array_slice($expected, 0, $stop + 1), $calls, $output);
            } else {
                self::assertSame(0, $code, $output);
                self::assertSame($expected, $calls);
            }
        }
    }

    public function testBuildPrerequisiteIsFixedAndFailuresStopPhpAndBrowserCommands(): void {
        $source = json_decode(file_get_contents(self::APP . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('npm run build @no_additional_args', $source['scripts']['assets:build']);
        foreach (['test', 'test:js'] as $command) {
            self::assertContains('@assets:build @no_additional_args', $source['scripts'][$command]);
            $scripts = $source['scripts'];
            $scripts['assets:build'] = '@php record.php build';
            $last = count($scripts[$command]) - 1;
            $scripts[$command][$last] = '@php record.php suite' . ($command === 'test:js' ? ' @no_additional_args' : '');
            file_put_contents($this->dir . '/composer.json', json_encode(['scripts' => $scripts], JSON_THROW_ON_ERROR));
            file_put_contents($this->dir . '/record.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/calls', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
exit($argv[1] === trim(file_get_contents(__DIR__ . '/fail')) ? 23 : 0);
PHP);
            foreach (['build', 'suite', 'none'] as $failure) {
                file_put_contents($this->dir . '/calls', '');
                file_put_contents($this->dir . '/fail', $failure);
                $process = proc_open(['composer', $command, '--', '--filter', 'quoted test'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->dir);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $code = proc_close($process);
                $calls = array_map(static fn ($line) => json_decode($line, true), file($this->dir . '/calls', FILE_IGNORE_NEW_LINES));
                $expected = [['build']];
                if ($failure !== 'build') {
                    $expected[] = $command === 'test' ? ['suite', '--filter', 'quoted test'] : ['suite'];
                }
                self::assertSame($expected, $calls, $output);
                self::assertSame($failure === 'none', $code === 0, $output);
            }
        }
    }

}
