<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Presentation\FilesGuide;
use OCA\Mcp\Modules\Files\Presentation\FilesPlanRenderer;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;

final class FilesModule implements ToolModule, PreviewsWrites, RendersPlans, ToolGuideNotes {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories, private \Closure $assertScopeReadable) {
        $expected = array_keys(FilesToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Files factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Files factories must be closures');
            }
        }
    }

    public function definitions(): array {
        return array_values(FilesToolCatalog::definitions());
    }

    public function guideNotes(): array {
        return FilesGuide::notes();
    }

    public function call(string $name, array $arguments, string $userId): array {
        ($this->assertScopeReadable)();
        return $this->resolve($name)->execute($arguments, new ToolContext($userId));
    }

    public function preview(string $name, array $arguments, string $userId): array {
        ($this->assertScopeReadable)();
        $tool = $this->resolve($name);
        if (!$tool instanceof WriteTool) {
            throw new \InvalidArgumentException('Tool has no write preview');
        }
        return $tool->preview($arguments, new ToolContext($userId));
    }

    public function renderPlan(string $tool, array $plan): ?string {
        return FilesPlanRenderer::render($tool, $plan);
    }

    private function resolve(string $name): Tool {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = ($this->factories[$name])();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Files factory resolved a different tool');
        }
        $definition = FilesToolCatalog::definitions()[$name];
        if ($definition['operation'] !== 'read' && !$tool instanceof WriteTool) {
            throw new \LogicException('Writing tools must implement WriteTool');
        }
        return $tool;
    }
}
