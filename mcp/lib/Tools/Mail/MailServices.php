<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Service\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Single place that knows the optional Nextcloud Mail classes by name, so the module compiles and the suite passes
 * with the mail app absent. Only the two read-side services the module needs are reachable from here: the account
 * service (accounts stored in Nextcloud) and the mailbox mapper (the folder list the Mail app keeps in its own
 * cache). Nothing here opens an IMAP connection.
 */
class MailServices {
	/** App id of Nextcloud Mail. */
	public const APP_ID = 'mail';

	/** OCA\Mail\Service\AccountService: the accounts of a user, as the Mail app stores them. */
	private const ACCOUNT_SERVICE_CLASS = 'OCA\\Mail\\Service\\AccountService';

	/** OCA\Mail\Db\MailboxMapper: the folder cache of the Mail app. */
	private const MAILBOX_MAPPER_CLASS = 'OCA\\Mail\\Db\\MailboxMapper';

	/** OCA\Mail\Exception\ClientException: what AccountService::find() throws for an account the user does not own. */
	public const CLIENT_EXCEPTION_CLASS = 'OCA\\Mail\\Exception\\ClientException';

	/**
	 * Receives the app manager, the container the Mail services are resolved from and the user manager.
	 *
	 * @param IAppManager $appManager app enablement, checked per user on every call
	 * @param ContainerInterface $container resolves the Mail services lazily by class name
	 * @param IUserManager $userManager resolves the UID to the IUser that IAppManager::isEnabledForUser() expects
	 * @return void
	 */
	public function __construct(
		private IAppManager $appManager,
		private ContainerInterface $container,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Whether the Mail app is enabled for the user, in a version MCP supports, on this very call.
	 *
	 * @param string $userId authenticated user UID
	 * @return bool true when the user may use the Mail app now
	 */
	public function isEnabledFor(string $userId): bool {
		$user = $this->userManager->get($userId);
		// A Mail older than MCP supports (AppEnablement::MINIMUM_VERSIONS) counts as off.
		return $user !== null && AppEnablement::forUser($this->appManager, self::APP_ID, $user);
	}

	/**
	 * OCA\Mail\Service\AccountService, used only through findByUserId() and find(), which read the account table.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Service\AccountService
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function accountService(string $userId): object {
		return $this->resolve(self::ACCOUNT_SERVICE_CLASS, $userId);
	}

	/**
	 * OCA\Mail\Db\MailboxMapper, used only through findAll(), which reads the folder cache.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Db\MailboxMapper
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function mailboxMapper(string $userId): object {
		return $this->resolve(self::MAILBOX_MAPPER_CLASS, $userId);
	}

	/**
	 * Resolves a Mail class by name only after the app is confirmed enabled and the class exists.
	 *
	 * @param string $class fully qualified Mail class name, never a hard reference from the module
	 * @param string $userId authenticated user, because app enablement is per user
	 * @return object the Mail service, untyped on purpose: the class may not exist in this installation
	 * @throws MailUnavailableException when Mail is disabled, the class is absent or the container returns no object
	 */
	protected function resolve(string $class, string $userId): object {
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		$service = $this->container->get($class);
		if (!is_object($service)) {
			throw new MailUnavailableException();
		}
		return $service;
	}
}
