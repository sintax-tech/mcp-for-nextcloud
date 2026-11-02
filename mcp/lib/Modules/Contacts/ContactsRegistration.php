<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts;

use OCA\Mcp\Mcp\Tools\ModuleRegistration;
use OCA\Mcp\Modules\Contacts\Integration\DavContactStore;
use OCA\Mcp\Modules\Contacts\Integration\EmbeddedCardDavServer;
use OCA\Mcp\Modules\Contacts\Locating\ContactStore;
use OCA\Mcp\Modules\Contacts\Writing\ContactDav;
use OCA\Mcp\Modules\Contacts\Writing\EmbeddedCardDavDispatcher;
use OCA\Mcp\Platform\Dav\Session;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The DI of the Contacts module. Its tools and work classes are built by the container from their constructors; what
 * needs a binding is the two ports the module reads and writes CardDAV through. The module has no capability probe.
 */
final class ContactsRegistration implements ModuleRegistration {
	/**
	 * {@inheritDoc}
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(ContactStore::class, static fn (ContainerInterface $c) => new DavContactStore($c));
		$context->registerService(ContactDav::class, static fn (ContainerInterface $c) => new EmbeddedCardDavDispatcher(
			static fn (): \Sabre\DAV\Server => EmbeddedCardDavServer::create(),
			new Session($c->get(IUserSession::class)), $c->get(IAppConfig::class), $c->get(LoggerInterface::class),
		));
	}

	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\CapabilityProbe>> none: every Contacts tool exists wherever the app does
	 */
	public function probes(): array {
		return [];
	}
}
