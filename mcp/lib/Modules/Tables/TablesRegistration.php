<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Tables\Integration\TablesWriteCapabilities;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Registers the lazy installation probe; work classes use ordinary autowiring. */
final class TablesRegistration implements ModuleRegistration {
	/**
	 * @param IRegistrationContext $context Bootstrap registrations.
	 * @return void No bindings required.
	 */
	public function register(IRegistrationContext $context): void {}
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> Native schema/sharing probe. */
	public function probes(): array { return [TablesWriteCapabilities::class]; }
}
