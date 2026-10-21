<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Tests\Support\Files\FilesToolsTestCase;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

/** Exact public compatibility across the complete Files migration. */
final class FilesMigrationCompatibilityTest extends FilesToolsTestCase {
    private function baseline(): array {
        return json_decode(file_get_contents(__DIR__ . '/../../fixtures/files-definitions.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAllFilesDefinitionsMatchBaseline(): void {
        self::assertSame($this->baseline()['definitions'], $this->module->definitions());
    }

    public static function publicationModes(): array {
        return array_map(static fn (string $mode): array => [$mode], ['all', 'read', 'share', 'link', 'none', 'noApps']);
    }

    #[DataProvider('publicationModes')]
    public function testToolsArePublishedOnceWithUnchangedGrantsAndApps(string $mode): void {
        foreach (GrantPolicy::CATALOG['files'] as $operation) {
            $this->sharePolicy->setGrant('alice', 'files', $operation, in_array($mode, ['all', 'noApps'], true) || $mode === $operation);
        }
        $this->enabled = $mode === 'noApps' ? [] : ['files_versions'];
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($this->sharePolicy, $this->apps, $this->users);
        $registry = new ToolRegistry([$this->module], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->createMock(LoggerInterface::class)));
        $actual = array_values(array_filter($registry->list('alice'), static fn(array $t): bool => str_starts_with($t['name'], 'files_')));
        self::assertSame($this->baseline()['published'][$mode], $actual);
        self::assertCount(count(array_unique(array_column($actual, 'name'))), $actual);
    }
}
