<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Forms\Integration\StructureCapabilities;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Registers the installation-wide probe of the native Forms structure API; tools and work classes are autowired. */
final class FormsRegistration implements ModuleRegistration {
	/** {@inheritDoc} */
	public function register(IRegistrationContext $context): void {
	}

	/** {@inheritDoc} */
	public function probes(): array {
		return [StructureCapabilities::class];
	}
}
