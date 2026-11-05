<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Deck\Integration\DeckCapabilities;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/** Names the Deck dependency probe. This ticket does not register it with the application. */
final class DeckRegistration implements ModuleRegistration {
	/** {@inheritDoc} */
	public function register(IRegistrationContext $context): void {}

	/** {@inheritDoc} */
	public function probes(): array { return [DeckCapabilities::class]; }
}
