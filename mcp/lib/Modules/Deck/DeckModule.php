<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Modules\Deck\Access\DeckAvailability;
use OCA\Mcp\Modules\Deck\Execution\ErrorMessages;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Presentation\DeckGuide;
use OCA\Mcp\Modules\Deck\Presentation\DeckPlanRenderer;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;

final class DeckModule implements ToolModule, PreviewsWrites, RendersPlans, ToolGuideNotes {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories, private DeckAvailability $availability) {
        $expected = array_keys(DeckToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Deck factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Deck factories must be closures');
            }
        }
    }

    public function definitions(): array {
        return array_values(DeckToolCatalog::definitions());
    }

    public function guideNotes(): array {
        return DeckGuide::notes();
    }

    public function call(
        string $name,
        array $arguments,
        string $userId,
    ): array {
        $this->assertAvailable($name, $userId);
        return $this->resolve($name)->execute($arguments, new ToolContext($userId));
    }

    public function preview(
        string $name,
        array $arguments,
        string $userId,
    ): array {
        if (!isset($this->factories[$name]) || DeckToolCatalog::definitions()[$name]['operation'] === 'read') {
            throw new \InvalidArgumentException(ErrorMessages::errorUnknownTool());
        }
        $tool = $this->resolve($name);
        if (!$tool instanceof WriteTool) {
            throw new \InvalidArgumentException('Tool has no write preview');
        }
        return $tool->preview($arguments, new ToolContext($userId));
    }

    public function renderPlan(string $tool, array $plan): ?string {
        return (new DeckPlanRenderer())->render($tool, $plan);
    }

    private function assertAvailable(string $name, string $userId): void {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException(ErrorMessages::errorUnknownTool());
        }
        $this->availability->assertFor($userId);
    }

    private function resolve(string $name): Tool {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Deck factory resolved a different tool');
        }
        $definition = DeckToolCatalog::definitions()[$name];
        if ($definition['operation'] !== 'read' && !$tool instanceof WriteTool) {
            throw new \LogicException('Writing tools must implement WriteTool');
        }
        return $tool;
    }
}
