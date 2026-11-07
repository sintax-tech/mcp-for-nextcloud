<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People;

use OCA\Mcp\Access\Guests\NativeGuests;
use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\People\Integration\NativeGuestsAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/** Binds the Guests port independently of account-search grants and native app availability. */
final class PeopleRegistration implements ModuleRegistration {
	/** {@inheritDoc} */
	public function register(IRegistrationContext $context): void {
		$context->registerService(NativeGuests::class, static fn (ContainerInterface $c): NativeGuests => $c->get(NativeGuestsAdapter::class));
	}

	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> none: Guests is checked by the request policy */
	public function probes(): array {
		return [];
	}
}
