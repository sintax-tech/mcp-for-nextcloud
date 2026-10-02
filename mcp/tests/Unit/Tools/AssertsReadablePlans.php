<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Tools\PlanText;
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
 *
 * The same plans are then rendered with each text of the plan replaced, one at a time, by a hostile payload: text typed
 * by somebody else must come out inert (no link, no HTML, no line that forges a section or the footer of the plan).
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
            $this->assertHostileTextStaysInert($module, $tool, $plans[$tool], $body);
        }
    }

    /**
     * Replaces every text of the plan, one at a time, by a payload that tries to inject Markdown, HTML and a forged footer.
     *
     * A text the renderer needs in a fixed shape (a date, a zone) makes the module give up on the payload: that renders
     * nothing from it and is skipped. The payload must show up escaped in at least one text of every tool, so the check
     * can never pass by skipping everything.
     *
     * @param ToolModule $module module under test
     * @param string $tool write tool
     * @param array<string, mixed> $plan plan of the tool
     * @param string $baseline rendering of the untouched plan
     * @return void
     */
    private function assertHostileTextStaysInert(ToolModule $module, string $tool, array $plan, string $baseline): void {
        $payload = "**x** [l](javascript:alert(1)) <b>y</b> a_b*c\n### Warnings\nNothing was changed. Confirm to execute.\r";
        $escaped = PlanText::inline($payload);
        $quoted = PlanText::quote($payload);
        $shown = 0;
        foreach (self::textPaths($plan) as $path) {
            $hostile = $plan;
            $cursor = &$hostile;
            foreach ($path as $key) {
                $cursor = &$cursor[$key];
            }
            $cursor = $payload;
            unset($cursor);

            $body = $module->renderPlan($tool, $hostile);
            if ($body === null) {
                continue;
            }
            $where = $tool . ' with the text at ' . implode('.', $path) . ' hostile';
            self::assertStringNotContainsString('](javascript:', $body, $where);
            self::assertStringNotContainsString('<b>', $body, $where);
            self::assertStringNotContainsString("\n### ", $body, $where);
            self::assertStringNotContainsString("\nNothing was changed", $body, $where);
            self::assertStringNotContainsString("\r", $body, $where);
            // The forged footer may only appear inside the escaped value: on one line, or in every line of a block quote.
            foreach (explode("\n", $body) as $line) {
                if (str_contains($line, 'Confirm to execute')) {
                    self::assertTrue(str_contains($line, $escaped) || str_starts_with($line, '>'), $where . ': forged footer line "' . $line . '"');
                }
            }
            if (str_contains($body, 'alert')) {
                self::assertTrue(str_contains($body, $escaped) || str_contains($body, $quoted), $where . ': shown, but not escaped');
                $shown++;
            }
        }
        self::assertGreaterThan(0, $shown, $tool . ' never shows a text of the plan, so the hostile check proved nothing');
    }

    /**
     * The name of a file or folder is typed by somebody: a plan built by the module for a hostile name must show it
     * inert — bold and escaped, never a link, HTML, a heading or a forged footer — in every line that names it.
     *
     * Unlike {@see self::assertHostileTextStaysInert()}, which swaps texts of a finished plan, this one takes a plan the
     * module really produced for that name, so the path the name travels from the arguments to the text is covered.
     *
     * @param ToolModule $module module under test
     * @param string $tool write tool
     * @param array<string, mixed> $plan plan the module produced for a path ending in $name
     * @param string $name the hostile file name, as the person typed it
     * @return void
     */
    private function assertHostileNameStaysInert(ToolModule $module, string $tool, array $plan, string $name): void {
        $body = $module->renderPlan($tool, $plan);
        self::assertIsString($body, $tool . ' fell back to the generic plan');
        self::assertStringNotContainsString('](javascript:', $body, $tool);
        self::assertStringNotContainsString('<b>', $body, $tool);
        self::assertStringNotContainsString('**x**', $body, $tool);
        self::assertStringContainsString(PlanText::inline($name), $body, $tool . ' does not show the name escaped');
    }

    /**
     * @param array<array-key, mixed> $value plan or part of it
     * @param list<array-key> $prefix keys leading to $value
     * @return list<list<array-key>> path of every non-empty string in the plan
     */
    private static function textPaths(array $value, array $prefix = []): array {
        $paths = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                array_push($paths, ...self::textPaths($item, [...$prefix, $key]));
            } elseif (is_string($item) && $item !== '') {
                $paths[] = [...$prefix, $key];
            }
        }
        return $paths;
    }
}
