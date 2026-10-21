<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Talk\Execution\ErrorMessages;
use OCA\Mcp\Modules\Talk\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Presentation\TalkPlanRenderer;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use OCA\Mcp\Mcp\Tools\ToolModule;

final class TalkModule implements ToolModule, PreviewsWrites, RendersPlans
{
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(
        private array $factories,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {
        $expected = array_keys(TalkToolCatalog::definitions());
        if (
            array_diff($expected, array_keys($factories)) !== [] ||
            array_diff(array_keys($factories), $expected) !== []
        ) {
            throw new \LogicException('Talk factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!($factory instanceof \Closure)) {
                throw new \LogicException('Talk factories must be closures');
            }
        }
    }

    public function definitions(): array
    {
        return array_values(TalkToolCatalog::definitions());
    }

    public function call(string $name, array $arguments, string $userId): array
    {
        $this->assertKnown($name);
        try {
            $tool = $this->resolve($name);
        } catch (\Throwable $e) {
            return $this->results->run($name, static fn(): array => throw $e);
        }
        // Tools already own the result envelope and error boundary.
        return $tool->execute($arguments, new ToolContext($userId));
    }

    public function preview(string $name, array $arguments, string $userId): array
    {
        $this->assertKnown($name);
        if (TalkToolCatalog::definitions()[$name]['operation'] === 'read') {
            throw new \InvalidArgumentException(ErrorMessages::unknownTool());
        }
        try {
            $tool = $this->resolve($name);
        } catch (\Throwable $e) {
            return $this->previews->run($name, static fn(): array => throw $e);
        }
        return $tool->preview($arguments, new ToolContext($userId));
    }

    public function renderPlan(string $tool, array $plan): ?string
    {
        return TalkPlanRenderer::render($tool, $plan);
    }

    private function assertKnown(string $name): void
    {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException(ErrorMessages::unknownTool());
        }
    }

    private function resolve(string $name): Tool
    {
        $tool = $this->factories[$name]();
        if (!($tool instanceof Tool) || $tool::definition()->name !== $name) {
            throw new \LogicException('Talk factory resolved a different tool');
        }
        if (TalkToolCatalog::definitions()[$name]['operation'] !== 'read' && !($tool instanceof WriteTool)) {
            throw new \LogicException('Writing tools must implement WriteTool');
        }
        return $tool;
    }
}
