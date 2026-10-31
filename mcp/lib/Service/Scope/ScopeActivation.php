<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Scope;

/**
 * The activation barrier of the resource scope, per module (issue #4, F1 of the ticket review). A scope may be saved
 * for a module only once every consumer of that module applies it: until then a saved scope would look accepted
 * while the tools, checkout and structured references ignored it. The state is a code constant, opened only by the
 * slice that wires all the consumers of the module (files in B5, calendar and tasks in B6); there is no occ command,
 * configuration or administrator flag for it. Reads stay available, since nothing can be saved to read yet.
 */
class ScopeActivation {
	/**
	 * Whether saving a scope is open, by scope module (decision D1). Every module is open: B5 narrows every Files
	 * consumer through the Files gate (tools, nc://files, checkout) and the structured references through the Files
	 * scope step; B6 narrows every calendar and task tool through ScopedCalendarAccess.
	 */
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
