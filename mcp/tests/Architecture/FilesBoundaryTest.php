<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\LibScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Files depends only on its capabilities, MCP contracts and explicitly shared platform services. */
final class FilesBoundaryTest extends TestCase {
    private const SHARED = [
        'OCA\Mcp\Mcp\Tools\ToolModule',
        'OCA\Mcp\Mcp\Confirmation\PreviewsWrites',
        'OCA\Mcp\Mcp\Confirmation\RendersPlans',
        'OCA\Mcp\Mcp\Guide\ToolGuideNotes',

        'OCA\Mcp\Mcp\Confirmation\PlanState',
        'OCA\Mcp\Mcp\Confirmation\PlanChanged',
        'OCA\Mcp\Access\Visibility\VisibilityGuard',
        'OCA\Mcp\Access\AppAvailability\AppEnablement',
        'OCA\Mcp\Access\Guests\GuestRestrictions',
        'OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite',
        'OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure',
        'OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess',
        'OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo',
        'OCA\Mcp\Platform\FileAccess\Paths\PathGuard',
        'OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard',
        'OCA\Mcp\L10n\Translator',
        'OCA\Mcp\Platform\Time\UserTimezone',
        'OCA\Mcp\Mcp\Confirmation\ConfirmationMessages',
        'OCA\Mcp\Platform\FileAccess\FileAccessMessages',
        'OCA\Mcp\Platform\FileAccess\Sharing\SharingMessages',
        'OCA\Mcp\Platform\FileAccess\Locks\LockMessages',
        'OCA\Mcp\Mcp\Tools\Results\ToolFailure',
        'OCA\Mcp\Mcp\Tools\Results\ToolResult',
        'OCA\Mcp\Mcp\Confirmation\PlanText',
        'OCA\Mcp\OAuth\TokenHasher',
        'OCA\Mcp\Access\Grants\GrantPolicy',
        'OCA\Mcp\Access\Policy\PolicyUnavailable',
        'OCA\Mcp\Access\ResourceScope\FilesAccessGuard',
        'OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException',
    ];

    /** Checks imports and fully qualified references; comments and string contents do not count. */
    private static function violations(string $source, bool $contract = false): array {
        $violations = [];
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = ltrim($token->text, '\\');
            if ($name === 'Psr\\Container\\ContainerInterface' || in_array($name, ['OCP\IServerContainer', 'OCP\AppFramework\IAppContainer', 'OCP\Server', 'OC\Server'], true)) {
                $violations[] = $name;
                continue;
            }
            if (!str_starts_with($name, 'OCA\\') || in_array($name, ['OCA\Mcp\Mcp\Tools', 'OCA\Mcp\Mcp\Tools\Tool', 'OCA\Mcp\Mcp\Tools\WriteTool', 'OCA\Mcp\Mcp\Tools\ToolContext', 'OCA\Mcp\Mcp\Tools\ToolDefinition', 'OCA\Mcp\Mcp\Tools\DavCollectionSchema'], true)) {
                continue;
            }
            if (!$contract && (($name === 'OCA\Mcp\Modules\Files' || str_starts_with($name, 'OCA\Mcp\Modules\Files\\') || str_starts_with($name, 'OCA\Mcp\Platform\FileAccess\\')) || in_array($name, self::SHARED, true))) {
                continue;
            }
            $violations[] = $name;
        }
        return array_values(array_unique($violations));
    }

    public static function forbiddenDependencies(): array {
        return [
            ['use OCA\Mcp\Tools' . '\Files\FilesModule;', 'OCA\Mcp\Tools' . '\Files\FilesModule'],
            ['use OCA\Mcp\Controller\McpController;', 'OCA\Mcp\Controller\McpController'],
            ['use Psr\Container\ContainerInterface;', 'Psr\Container\ContainerInterface'],
            ['new \\OCA\Mcp\Modules\Deck\DeckModule();', 'OCA\Mcp\Modules\Deck\DeckModule'],
        ];
    }

    #[DataProvider('forbiddenDependencies')]
    public function testDetectsRepresentativeForbiddenDependencies(string $code, string $name): void {
        self::assertSame([$name], self::violations('<?php ' . $code));
    }

    public function testContractsCannotDependOnAFeature(): void {
        self::assertSame(['OCA\Mcp\Modules\Files\Tools\ReadFile'], self::violations('<?php use OCA\Mcp\Modules\Files\Tools\ReadFile;', true));
    }

    public function testFilesSourcesRespectTheirBoundaries(): void {
        foreach (['Tool', 'ToolContext', 'ToolDefinition', 'WriteTool', 'DavCollectionSchema'] as $contract) {
            $file = __DIR__ . '/../../lib/Mcp/Tools/' . $contract . '.php';
            self::assertSame([], self::violations(file_get_contents($file), true), $file);
        }
        foreach (['Modules/Files' => false] as $subtree => $contract) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../lib/' . $subtree, \FilesystemIterator::SKIP_DOTS));
            $count = 0;
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $count++;
                    self::assertSame([], self::violations(file_get_contents($file->getPathname()), $contract), $file->getPathname());
                }
            }
            self::assertGreaterThan(0, $count);
        }
    }

    public function testNextcloudApiScannerIncludesExtractedCapabilities(): void {
        $scanner = new LibScanner(__DIR__ . '/../../lib');
        foreach (['Modules/Files/Reading/FileReader.php', 'Modules/Files/Editing/TextFileEditor.php'] as $file) {
            self::assertContains($file, $scanner->classes()['OCP\Files\IRootFolder']);
            self::assertContains($file, $scanner->methods()['getUserFolder']);
        }
    }
    public function testTextWritesShareTheVerifiedCommitCapability(): void {
        foreach ([\OCA\Mcp\Modules\Files\Editing\TextFileEditor::class, \OCA\Mcp\Modules\Files\Editing\TextReplacer::class] as $class) {
            self::assertTrue(class_exists($class), $class);
            $types = array_map(static fn (\ReflectionParameter $p): string => (string)$p->getType(), (new \ReflectionClass($class))->getConstructor()->getParameters());
            self::assertContains(\OCA\Mcp\Modules\Files\Editing\ContentWriter::class, $types, 'Text writes must share verified backup/write/receipt orchestration');
        }
    }

    /** Includes string service IDs and serialized autoload mappings, not only PHP imports. */
    private static function retiredReferences(string $source): array {
        $source = str_replace('\\\\', '\\', $source);
        $needles = [
            'OCA\Mcp\Tools' . '\Files', 'OCA\Mcp' . '\Checkout',
            'OCA\Mcp\Tests\Unit\Tools' . '\Files', 'OCA\Mcp\Tests\Unit' . '\Checkout',
            'lib/Tools' . '/Files', 'lib' . '/Checkout',
            'tests/Unit/Tools' . '/Files', 'tests/Unit' . '/Checkout',
        ];
        return array_values(array_filter($needles, static fn (string $needle): bool => str_contains($source, $needle)));
    }

    public function testRetiredReferenceAuditIncludesServiceIdsAndAutoloadPaths(): void {
        $retired = 'OCA\Mcp\Tools' . '\Files\FilesModule';
        self::assertNotEmpty(self::retiredReferences('<?php use ' . $retired . ';'));
        self::assertNotEmpty(self::retiredReferences('$container->get(' . var_export($retired, true) . ');'));
        self::assertNotEmpty(self::retiredReferences(json_encode(['class' => $retired], JSON_THROW_ON_ERROR)));
        self::assertNotEmpty(self::retiredReferences('lib/Tools' . '/Files/FilesModule.php'));
        self::assertSame([], self::retiredReferences(FilesBoundaryTest::class));
    }

    public function testNoExecutableOrSupportSourceReferencesRetiredFilesOwners(): void {
        $root = dirname(__DIR__, 3);
        $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($paths as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!preg_match('~^(?:mcp/(?:lib|tests|appinfo|scripts)/|scripts/|\.github/|mcp/(?:composer\.(?:json|lock)|phpunit\.xml)$)~', $relative)
                || !in_array($file->getExtension(), ['php', 'json', 'xml', 'sh', 'py', 'yml', 'yaml', 'lock'], true)) {
                continue;
            }
            self::assertSame([], self::retiredReferences(file_get_contents($file->getPathname())), $relative);
        }
    }

    public function testSharedBackupPrimitivesDoNotDependOnFilesCapabilities(): void {
        foreach (glob(__DIR__ . '/../../lib/Platform/FileAccess/*.php') as $file) {
            foreach (\PhpToken::tokenize(file_get_contents($file)) as $token) {
                if (in_array($token->id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    self::assertStringNotContainsString('OCA\Mcp\Modules\Files', $token->text, $file);
                }
            }
        }
    }
}
