<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The DI of one {@see CatalogModule} (`<App>Registration`): the bindings of that module only and its capability
 * probes. Application walks Application::MODULES and runs the registration each module names, so no module factory
 * lives in Application. It is built with `new` and takes no constructor argument.
 */
interface ModuleRegistration {
	/**
	 * Registers the services of this module.
	 *
	 * @param IRegistrationContext $context registration context of the app
	 */
	public function register(IRegistrationContext $context): void;

	/**
	 * @return list<class-string<CapabilityProbe>> probes of this module, resolved from the container when first asked
	 */
	public function probes(): array;
}
