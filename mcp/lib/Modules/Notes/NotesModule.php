<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
final class NotesModule implements \OCA\Mcp\Mcp\Tools\ToolModule, \OCA\Mcp\Mcp\Confirmation\PreviewsWrites, \OCA\Mcp\Mcp\Confirmation\RendersPlans, \OCA\Mcp\Mcp\Guide\ToolGuideNotes
{

    /** @param array<string, \Closure(): Tool> $factories */

    public function __construct(private array $factories)
    {
        $expected = array_keys(NotesToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }

    public function definitions(): array
    {
        return array_values(NotesToolCatalog::definitions());
    }

    private function resolve(string $name): Tool
    {
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }
        if (NotesToolCatalog::definitions()[$name]['operation'] !== 'read' && !$tool instanceof WriteTool) {
            throw new \LogicException('Writes must implement WriteTool');
        }
        return $tool;
    }

    private function known(string $name): void
    {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
    }

    public function preview(string $name, array $arguments, string $userId): array
    {
        $this->known($name);
        if (NotesToolCatalog::definitions()[$name]['operation'] === 'read') {
            throw new \InvalidArgumentException('Unknown tool');
        }
        return $this->resolve($name)->preview($arguments, new ToolContext($userId));
    }

    public function call(string $name, array $arguments, string $userId): array
    {
        $this->known($name);
        return $this->resolve($name)->execute($arguments, new ToolContext($userId));
    }

    /**
     * What the schemas cannot say: how a note is named and what the module refuses to do.
     *
     * @return list<string>
     */

    public function guideNotes(): array
    {
        return ['A note is addressed by the `id` notes_list or notes_search returns, not by its title; the title is the file name and ' . 'the category is the subfolder it sits in.', 'Use notes_search to find notes by keyword in title or Markdown content.', 'notes_create never overwrites: when the title is taken in that category it picks a free name, and the ' . 'response says which name it used.', 'notes_delete only deletes where the Nextcloud trash bin takes the note back; anywhere else it refuses ' . 'instead of removing it for good.', 'Notes are capped at ' . \OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES . ' bytes read or written, and a title at 200 characters.', 'Pass `etag` to make a change fail instead of overwriting somebody else\'s version of the same note.', 'Some notes or categories may be hidden by administrator policy and will appear as if they do not exist.'];
    }

    public function renderPlan(string $name, array $plan): ?string
    {
        return (new Presentation\NotesPlanRenderer())->render($name, $plan);
    }
}
