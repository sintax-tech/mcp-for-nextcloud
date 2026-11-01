<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

/**
 * Answers installation-wide capability keys of one module (`<module>.<capability>`, listed in
 * {@see ToolDefinition::$requires}); it lives in the module's `Integration/` folder.
 *
 * A probe is about the installation, never about a user. It may read IAppManager (version, enablement), the Nextcloud
 * version and introspect native classes without building them (class_exists, method_exists, property_exists). It
 * must not build a native service, query the database, use the network or the user session. Whatever it throws
 * counts as false, see {@see Discovery\CapabilityRegistry}.
 */
interface CapabilityProbe {
	/**
	 * @return list<string> keys this probe answers, e.g. `['deck.dependencies']`
	 */
	public function keys(): array;

	/**
	 * @param string $key one of keys()
	 * @return bool true only when every condition of the capability holds
	 */
	public function supports(string $key): bool;
}
