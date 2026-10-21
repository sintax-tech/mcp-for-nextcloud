<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class DavCatalogTest extends TestCase {
    public static function modules(): array { return [['Calendar',7], ['Contacts',6], ['Tasks',7]]; }
    #[DataProvider('modules')]
    public function testMetadataGuideAndSavedPlanRenderingDoNotResolveAnyTool(string $feature, int $count): void {
        \OCA\Mcp\L10n\Translator::reset();
        $catalog = 'OCA\Mcp\Modules\\'.$feature.'\\'.$feature.'ToolCatalog';
        $class = 'OCA\Mcp\Modules\\'.$feature.'\\'.$feature.'Module';
        $factories = [];
        foreach ($catalog::classes() as $tool) { $factories[$tool::definition()->name] = static fn () => throw new \LogicException('Tool resolved during metadata'); }
        $module = $feature === 'Contacts' ? new $class($factories) : new $class($factories,
            (\OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this))->assertAvailable(...));
        self::assertCount($count, $module->definitions());
        self::assertNotEmpty($module->guideNotes());
        $cases = json_decode(file_get_contents(__DIR__.'/../../Support/Dav/Compatibility/plans.json'), true, 512, JSON_THROW_ON_ERROR)['plans'];
        foreach ($cases as $name=>$case) {
            if (str_starts_with($name, strtolower($feature).'_')) { self::assertSame($case['rendered'], $module->renderPlan($name, $case['plan'])); }
        }
        foreach (['unknown', array_key_first($factories)] as $name) {
            try { $module->preview($name, [], 'alice'); self::fail('Read and unknown tools have no preview'); }
            catch (\InvalidArgumentException $e) { self::assertSame('Unknown tool', $e->getMessage()); }
        }
    }
    #[DataProvider('modules')]
    public function testCatalogRejectsDuplicatesAndAnUngatedWrite(string $feature): void {
        $catalog = 'OCA\Mcp\Modules\\'.$feature.'\\'.$feature.'ToolCatalog';
        $first = $catalog::classes()[0];
        foreach ([[$first, $first], [InvalidDavWrite::class]] as $classes) {
            try { $catalog::definitions($classes); self::fail('Invalid catalog accepted'); }
            catch (\LogicException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }
}
final class InvalidDavWrite implements \OCA\Mcp\Mcp\Tools\Tool {
    public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
        return new \OCA\Mcp\Mcp\Tools\ToolDefinition('invalid_write', 'calendar', 'edit', 'Invalid fixture', []);
    }
    public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array { throw new \LogicException('Must not execute'); }
}
