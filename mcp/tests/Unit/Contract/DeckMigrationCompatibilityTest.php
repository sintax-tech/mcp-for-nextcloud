<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Modules\Deck\DeckModule;
use PHPUnit\Framework\TestCase;

/** Preserve exact metadata, including absent defaults and raw field order. */
final class DeckMigrationCompatibilityTest extends TestCase {
    public function testDefinitionsAndGuideMatchTheReviewedBaseline(): void {
        $module = (new \ReflectionClass(DeckModule::class))->newInstanceWithoutConstructor();
        $expected = json_decode(file_get_contents(__DIR__ . '/../../fixtures/deck-definitions.json'), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode([
            'definitions' => $module->definitions(), 'guide' => $module->guideNotes(),
        ], JSON_THROW_ON_ERROR));
    }
}
