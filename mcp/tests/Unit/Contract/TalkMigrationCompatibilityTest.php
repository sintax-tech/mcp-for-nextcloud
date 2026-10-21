<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Mcp\Guide\ToolGuide;
use PHPUnit\Framework\TestCase;

final class TalkMigrationCompatibilityTest extends TestCase {
    public function testRawPublishedDefinitionsAndGuideMatchBaseline(): void {
        \OCA\Mcp\L10n\Translator::reset();
        $module = (new \ReflectionClass(TalkModule::class))->newInstanceWithoutConstructor();

        $raw = $module->definitions();
        $published = array_map(fn (array $d): array => \OCA\Mcp\Mcp\Tools\Discovery\ToolPublication::present($d), $raw);
        $actual = ['raw' => $raw, 'published' => $published,
            'guide' => (new ToolGuide([$module]))->result($published, ['module' => 'talk'])];
        $expected = json_decode(file_get_contents(__DIR__ . '/../../fixtures/talk-definitions.json'), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::canonical($expected), self::canonical(json_decode(json_encode($actual, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)));
    }

    private static function canonical(mixed $value): mixed {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields);
            return ['object' => array_map(self::canonical(...), $fields)];
        }
        return is_array($value) ? array_map(self::canonical(...), $value) : $value;
    }
}
