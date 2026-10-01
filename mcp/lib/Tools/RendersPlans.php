<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/** A module that renders its own write plans as Markdown for the person who confirms them. */
interface RendersPlans {
    /**
     * @param string $tool tool name
     * @param array<string, mixed> $plan the plan returned by preview()
     * @return string|null Markdown body (no title, no warnings, no footer), or null to use the generic body
     */
    public function renderPlan(string $tool, array $plan): ?string;
}