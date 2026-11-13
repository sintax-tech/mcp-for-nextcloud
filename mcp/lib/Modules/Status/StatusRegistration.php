<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Status\Integration\StatusCapabilities;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Registers the lazy native surface probe; all work classes autowire. */
final class StatusRegistration implements ModuleRegistration {
	/** @param IRegistrationContext $context no special construction is needed */
	public function register(IRegistrationContext $context): void {
	}

	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> installation-only capability */
	public function probes(): array {
		return [StatusCapabilities::class];
	}
}
