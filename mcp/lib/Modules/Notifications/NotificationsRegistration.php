<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Notifications\Integration\NotificationsCapabilities;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Registers only the lazy surface probe; native services resolve after admission. */
final class NotificationsRegistration implements ModuleRegistration {
	/** @param IRegistrationContext $context work classes use normal autowiring */
	public function register(IRegistrationContext $context): void {}
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> installation probe */
	public function probes(): array { return [NotificationsCapabilities::class]; }
}
