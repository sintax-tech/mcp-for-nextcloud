<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegrationHarnessTest extends TestCase {
    /** @return array{int,string,string} */
    private function invoke(array $arguments): array {
        $app = dirname(__DIR__, 3);
        $process = proc_open(['sh', $app . '/tests/Integration/ci/run-local.sh', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out, $error];
    }

    public function testPlanResolvesPinnedCellsWithoutStartingContainers(): void {
        foreach ([[32, 'mariadb', 'fresh'], [33, 'mariadb', 'upgrade'], [34, 'mariadb', 'fresh'],
            [35, 'pgsql', 'upgrade'], [35, 'sqlite', 'fresh'], [35, 'mariadb', 'upgrade-local'],
            [35, 'mariadb', 'replace-upstream'], [35, 'mariadb', 'upgrade-upstream'], [35, 'mariadb', 'upgrade-upstream-111']] as [$nc, $db, $mode]) {
            [$status, $out, $error] = $this->invoke(['--plan', (string)$nc, $db, $mode]);
            self::assertSame(0, $status, $error);
            $plan = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame((string)$nc, $plan['nc']);
            self::assertSame($db, $plan['db']);
            self::assertSame($mode, $plan['mode']);
            self::assertMatchesRegularExpression('/@sha256:[a-f0-9]{64}$/', $plan['nc_image']);
            self::assertFalse($plan['publishes_ports']);
            self::assertTrue($plan['disposable']);
            if ($mode === 'upgrade') self::assertSame('a1a7911cc72dcf2487d17e06e23edfa89b0ecf92', $plan['baseline_commit']);
            if ($mode === 'upgrade-local') self::assertSame('1f0b4232e18b2c778d3585b878116d1cabb93d05', $plan['baseline_commit']);
            if ($mode === 'replace-upstream') self::assertSame('27da5e107ca06d92b7af9b8a80ad9c77c12ba91e', $plan['baseline_commit']);
            if ($mode === 'upgrade-upstream-111') self::assertSame('65e4e08b68c3f8d1ba78398a082ec567b0b0f4e4', $plan['baseline_commit']);
            if ($mode === 'upgrade-upstream') self::assertSame('0911f9123a661968e707fc4c391adcf9d37c3e84', $plan['baseline_commit']);
        }
    }

    public function testNativeProbeRequiresExplicitCompletionEvenWhenHostHandlerExitsSuccessfully(): void {
        $guard = dirname(__DIR__, 3) . '/tests/Integration/Support/ProbeCompletion.php';
        foreach ([false, true] as $complete) {
            $code = 'require ' . var_export($guard, true) . '; $probe = new OCA\\Mcp\\Tests\\Integration\\Support\\ProbeCompletion();'
                . ($complete ? '$probe->complete();' : 'try { throw new RuntimeException("host-handled failure"); } catch (Throwable $e) { exit(0); }');
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame($complete ? 0 : 1, proc_close($process), $error);
            self::assertSame('', $out);
            self::assertSame($complete ? '' : "Native probe did not complete.\n", $error);
        }
    }

    public static function invalid(): array {
        return ['empty' => [[]], 'unsupported host' => [['--plan', '31', 'sqlite', 'fresh']],
            'unsupported combination' => [['--plan', '32', 'pgsql', 'fresh']],
            'shell input' => [['--plan', '35; exit 0', 'sqlite', 'fresh']],
            'shared daemon shutdown' => [['--shutdown']], 'image pruning' => [['35', 'sqlite', 'fresh', '--prune-images']],
            'unknown mode' => [['--plan', '35', 'sqlite', 'deploy']]];
    }

    #[DataProvider('invalid')]
    public function testInvalidRequestsFailBeforeAnyExecution(array $args): void {
        [$status, , $error] = $this->invoke($args);
        self::assertNotSame(0, $status);
        self::assertNotSame('', $error);
    }
}
