<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** Enforces the final shared owners, including string service IDs and loaders. */
final class SharedMcpBoundaryTest extends TestCase {
    private static function retired(string $source): array {
        // Inspect each PHP token separately: deliberate negative fragments are not executable routes.
        // Comments may cite a third-party app's Service directory; they are not our loaders.
        $parts = str_starts_with($source, '<?php')
            ? array_map(static fn ($t) => $t->text, array_filter(\PhpToken::tokenize($source),
                static fn ($t) => !in_array($t->id, [T_COMMENT, T_DOC_COMMENT], true))) : [$source];
        $bad = [];
        foreach ($parts as $part) {
            $part = str_replace('\\\\', '\\', $part);
            if (preg_match('~OCA\\\\Mcp\\\\(?:Tests\\\\Unit\\\\)?(?:Tools|Service)\\\\[A-Za-z_]~', $part)
                || preg_match('~(?:lib|tests/Unit)/(?:Tools|Service)/[A-Za-z_]~', $part)) {
                $bad[] = $part;
            }
        }
        return $bad;
    }

    private static function dependencies(string $source, string $path): array {
        $bad = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) { continue; }
            $name = ltrim(str_replace('\\\\', '\\', trim($token->text, "'\"")), '\\');
            if (in_array($name, ['class_alias', 'OC', 'OC\Server', 'OCP\Server', 'OCP\IServerContainer',
                'OCP\AppFramework\IAppContainer', 'Psr\Container\ContainerInterface'], true)
                || str_starts_with($name, 'OCA\Mcp\Tests\\') || str_starts_with($name, 'PHPUnit\\')) {
                $bad[] = $name;
            }
            $orchestrator = $name === 'OCA\Mcp\Mcp\Tools\ToolRegistry'
                || $name === 'OCA\Mcp\Mcp\Tools\Execution\ToolExecutor'
                || str_starts_with($name, 'OCA\Mcp\Mcp\Protocol\\');
            if ($orchestrator && !str_starts_with($path, 'Mcp/')) { $bad[] = $name; }
            if (!str_starts_with($path, 'Modules/') && str_starts_with($name, 'OCA\Mcp\Modules\\')) {
                $readers = ['OCA\Mcp\Modules\Files\Contracts\FileResourceReader', 'OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader'];
                if ($path !== 'Mcp/Resources/ResourceRegistry.php' || !in_array($name, $readers, true)) { $bad[] = $name; }
            }
            if (str_starts_with($path, 'Access/') && str_starts_with($name, 'OCA\Mcp\Mcp\\')
                && $name !== 'OCA\Mcp\Mcp\Tools\Results\ToolFailure') { $bad[] = $name; }
        }
        return array_values(array_unique($bad));
    }

    public function testScannerRejectsOldImportsStringsLoadersAndAliases(): void {
        foreach (['OCA\\Mcp\\'.'Tools\\ToolRegistry', 'OCA\\Mcp\\'.'Service\\GrantPolicy',
            'OCA\\Mcp\\Tests\\Unit\\'.'Tools\\FakeTree', 'OCA\\Mcp\\Tests\\Unit\\'.'Service\\ResourceRegistryTest'] as $name) {
            foreach (['<?php use '.$name.';', '<?php $id = '.var_export($name, true).';', json_encode(['class' => $name])] as $source) {
                self::assertNotEmpty(self::retired($source));
            }
        }
        foreach (['lib/'.'Tools/ToolRegistry.php', 'lib/'.'Service/McpProtocol.php', 'tests/Unit/'.'Tools/FakeTree.php', 'tests/Unit/'.'Service/ResourceRegistryTest.php'] as $path) {
            self::assertNotEmpty(self::retired('<?php require '.var_export($path, true).';'));
        }
        foreach (['<?php class_alias(NewOwner::class, OldOwner::class);', '<?php \\class_alias(NewOwner::class, OldOwner::class);'] as $source) {
            self::assertNotEmpty(self::dependencies($source, 'Mcp/Tools/ToolRegistry.php'));
        }
        foreach (['<?php OC::$server->get(X::class);', '<?php use OCP\Server as Services; Services::get(X::class);', '<?php use Psr\Container\ContainerInterface;'] as $source) {
            self::assertNotEmpty(self::dependencies($source, 'Access/Grants/GrantPolicy.php'));
        }
    }

    public function testScannerRejectsForbiddenEdgesWithoutPrefixFalsePositives(): void {
        foreach (['ToolRegistry', 'Execution\\ToolExecutor'] as $name) {
            self::assertNotEmpty(self::dependencies('<?php use OCA\\Mcp\\Mcp\\Tools\\'.$name.';', 'Modules/Files/Tools/ReadFile.php'));
        }
        self::assertNotEmpty(self::dependencies('<?php use OCA\Mcp\Mcp\Protocol\ToolRequests;', 'Modules/Files/Tools/ReadFile.php'));
        self::assertNotEmpty(self::dependencies('<?php use OCA\Mcp\Modules\Files\Reading\FileReader;', 'Mcp/Protocol/ToolRequests.php'));
        self::assertNotEmpty(self::dependencies('<?php use OCA\Mcp\Mcp\Tools\Execution\ToolExecutor;', 'Platform/FileAccess/Locks/LockAwareWrite.php'));
        self::assertSame([], self::dependencies('<?php use OCA\Mcp\Modules\Files\Contracts\FileResourceReader;', 'Mcp/Resources/ResourceRegistry.php'));
        self::assertSame([], self::retired('<?php use OCA\Mcp\Mcp\Tools\ToolRegistry; use OCA\Mcp\Modules\Files\Tools\ReadFile;'));
        self::assertSame([], self::dependencies('<?php use OCA\Mcp\Mcp\Tools\Results\ToolFailure;', 'Access/Visibility/VisibilityGuard.php'));
    }

    public function testNoRetiredRuntimeSupportOrLoaderRemains(): void {
        $app = dirname(__DIR__, 2);
        foreach (['lib/'.'Tools', 'lib/'.'Service', 'tests/Unit/'.'Tools', 'tests/Unit/'.'Service'] as $path) {
            self::assertDirectoryDoesNotExist($app.'/'.$path);
        }
        foreach (['lib', 'tests', 'scripts', 'appinfo', '../.github'] as $subtree) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app.'/'.$subtree, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'json', 'xml', 'sh', 'py', 'yaml', 'yml'], true)) { continue; }
                $source = file_get_contents($file->getPathname());
                // Only these separate-process probes intentionally address immutable original baselines.
                // Remove exact quoted class IDs; every other token in these files remains scanned.
                $relative = substr($file->getPathname(), strlen($app) + 1);
                $baselineIds = [
                    'tests/Integration/ci/legacy-policy.php' => ['OCA\\Mcp\\'.'Service\\GrantPolicy'],
                    'tests/Integration/Compat/UpgradeSnapshotTest.php' => ['OCA\\Mcp\\'.'Service\\GrantPolicy'],
                    'tests/Integration/ci/replacement.php' => ['OCA\\Mcp\\'.'Service\\GrantPolicy', 'OCA\\Mcp\\'.'Service\\Policy\\PolicyWriter'],
                ];
                foreach ($baselineIds[$relative] ?? [] as $id) {
                    $source = str_replace([var_export($id, true), "'" . $id . "'"], "'PinnedBaselineClass'", $source);
                }
                self::assertSame([], self::retired($source), $file->getPathname());
                $path = substr($file->getPathname(), strlen($app.'/lib/'));
                if ($subtree === 'lib' && preg_match('~^(?:Mcp/|Access/|Platform/(?:FileAccess|Time|Logging)/|OAuth/Connections/|Modules/)~', $path)) {
                    self::assertSame([], self::dependencies($source, $path), $path);
                }
            }
        }
        foreach (['composer.json', 'composer.lock', 'phpunit.xml'] as $path) {
            self::assertSame([], self::retired(file_get_contents($app.'/'.$path)), $path);
        }
    }
}
