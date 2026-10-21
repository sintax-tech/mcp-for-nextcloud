<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

/**
 * Names the modules whose complete consumer graph enforces resource scope. Unknown modules are refused.
 * Files, Calendar and Tasks are active in the final composition; there is no runtime administrator flag
 * that can claim a restriction before its tools, resources and cross-module references enforce it.
 */
class ScopeActivation {
    /** Every resource and reference consumer enforces these modules before their barrier opens. */
    public const MODULES = [
        'files' => true,
        'calendar' => true,
        'tasks' => true,
    ];

    /**
     * @param array<string, bool> $active activation by module; the container always uses MODULES, and only tests
     *        pass another map to exercise the writer behind an open barrier
     */
    public function __construct(private array $active = self::MODULES) {}

    /** @return bool whether a scope may be saved for the module; an unknown module is never active */
    public function isActive(string $module): bool {
        return ($this->active[$module] ?? false) === true;
    }

    /** @return array<string, bool> activation by module, for the admin page */
    public function modules(): array {
        $modules = [];
        foreach (array_keys(self::MODULES) as $module) {
            $modules[$module] = $this->isActive($module);
        }
        return $modules;
    }
}
