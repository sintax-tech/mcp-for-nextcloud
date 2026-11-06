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
use Psr\Container\ContainerInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceGateway;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCP\IConfig;

/** Registers the unchanged lazy Deck gateway, Talk card port and native dependency probe. */
final class DeckRegistration implements ModuleRegistration {
	/**
	 * {@inheritDoc}
	 * @param IRegistrationContext $context Nextcloud bootstrap registration context
	 * @return void
	 */
	public function register(IRegistrationContext $context): void {
		// The Talk references read Deck cards through the same gateway the Deck tools use; it resolves Deck lazily.
		$context->registerService(DeckGatewayInterface::class, static fn (ContainerInterface $c): DeckGatewayInterface => new DeckServiceGateway(
			$c,
			$c->get(ITimeFactory::class),
			new UserTimezone($c->get(IConfig::class)),
			$c->get(\OCP\ISession::class),
		));
		$context->registerService(\OCA\Mcp\Access\References\ReadableDeckCard::class, static fn (ContainerInterface $c): \OCA\Mcp\Access\References\ReadableDeckCard => $c->get(\OCA\Mcp\Modules\Deck\DeckCardReference::class));
	}

	/**
	 * {@inheritDoc}
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> unchanged Deck capability probe
	 */
	public function probes(): array { return [DeckCapabilities::class]; }
}
