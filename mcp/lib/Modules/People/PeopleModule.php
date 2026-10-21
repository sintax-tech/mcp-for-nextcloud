<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;

/** Static publication and lazy dispatch for the read-only People feature. */
final class PeopleModule implements ToolModule, ToolGuideNotes {
    /** @param array<string, \Closure(): Tool> $factories */
    public function __construct(private array $factories) {
        $expected = array_keys(PeopleToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }

    public function definitions(): array {
        return array_values(PeopleToolCatalog::definitions());
    }

    public function call(string $name, array $arguments, string $userId): array {
        if (!isset($this->factories[$name])) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }
        return $tool->execute($arguments, new ToolContext($userId));
    }

    /** @return list<string> short behaviour notes about this module */
    public function guideNotes(): array {
        return [
            'Before acting on a person (inviting, mentioning, messaging), when the user did not give the account ID, '
                . 'call users_search and confirm with the user when more than one account matches. Never invent an ID.',
            'users_search follows the sharing settings of the administrator: it may return nobody for a partial term.',
            'To share something with a whole group, call users_search with include_groups: true and take the ID of the '
                . 'row whose type is "group": a share takes it as group:<id>. Groups are not searched without that '
                . 'argument, and the same administrator restrictions of accounts apply to them.',
        ];
    }

}
