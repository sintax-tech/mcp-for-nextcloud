<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\WriteGate;

/**
 * The readability contract of write plans: every writing tool of a module has a plan the module itself turns into
 * text for the person confirming it, so none of them falls back to the generic field list.
 *
 * The module's own test builds the plans with the fakes it already uses and hands them over keyed by tool name.
 * A write tool without a plan fails the test, so a tool added later cannot ship without a human text.
 * {@see \OCA\Mcp\Tests\Unit\Tools\WritePlanContractTest} checks that every writing module uses this trait.
 */
trait AssertsReadablePlans {
    /**
     * @param ToolModule $module module under test
     * @param array<string, array<string, mixed>> $plans plan of each write tool, as the module produced it
     * @return void
     */
    private function assertEveryWriteToolHasAReadablePlan(ToolModule $module, array $plans): void {
        self::assertInstanceOf(PreviewsWrites::class, $module);
        self::assertInstanceOf(RendersPlans::class, $module);
        $writes = array_values(array_map(
            static fn (array $definition): string => $definition['name'],
            array_filter($module->definitions(), static fn (array $definition): bool => WriteGate::isWrite($definition)),
        ));
        self::assertNotSame([], $writes, 'the module has no write tool, so it does not belong in this contract');
        self::assertSame([], array_values(array_diff($writes, array_keys($plans))), 'write tools without a plan in the readability contract');
        foreach ($writes as $tool) {
            $body = $module->renderPlan($tool, $plans[$tool]);
            self::assertIsString($body, $tool . ' fell back to the generic plan');
            self::assertNotSame('', trim($body), $tool . ' rendered an empty plan');
        }
    }
}
