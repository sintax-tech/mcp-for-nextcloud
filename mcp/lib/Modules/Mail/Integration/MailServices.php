<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Single place that knows the optional Nextcloud Mail classes by name, so the module compiles and the suite passes
 * with the mail app absent. Only read-side Mail classes are reachable from here: the account service (accounts stored
 * in Nextcloud), the mailbox and message mappers (the folder and message cache of the Mail app), the mail manager
 * (one owned message, its folder, its thread and its body), the IMAP client factory of the Mail app (its own login,
 * cache and rate limit), its IMAP message mapper (one attachment, on a client already checked) and its body-search
 * provider, plus the search query and flag value objects, and the names of its flag and delete events. Resolving a
 * service opens no IMAP connection; only the body read, the attachment read, the flag change, the move and the body
 * search do, through these Mail classes.
 */
class MailServices {
	/** App id of Nextcloud Mail. */
	public const APP_ID = 'mail';

	/** OCA\Mail\Service\AccountService: the accounts of a user, as the Mail app stores them. */
	private const ACCOUNT_SERVICE_CLASS = 'OCA\\Mail\\Service\\AccountService';

	/** OCA\Mail\Db\MailboxMapper: the folder cache of the Mail app. */
	private const MAILBOX_MAPPER_CLASS = 'OCA\\Mail\\Db\\MailboxMapper';

	/** OCA\Mail\Db\MessageMapper: the message cache of the Mail app, searched without the preview enhancer. */
	private const MESSAGE_MAPPER_CLASS = 'OCA\\Mail\\Db\\MessageMapper';

	/** OCA\Mail\Service\MailManager: one owned message, its folder, its thread and its body (behind IMailManager). */
	private const MAIL_MANAGER_CLASS = 'OCA\\Mail\\Service\\MailManager';

	/** OCA\Mail\IMAP\IMAPClientFactory: the IMAP client of the Mail app, with its own login, cache and rate limit. */
	private const CLIENT_FACTORY_CLASS = 'OCA\\Mail\\IMAP\\IMAPClientFactory';

	/** OCA\Mail\IMAP\MessageMapper: the IMAP side of the Mail app, used only to read one attachment on a given client. */
	private const IMAP_MESSAGE_MAPPER_CLASS = 'OCA\\Mail\\IMAP\\MessageMapper';

	/** OCA\Mail\Events\MessageFlaggedEvent: the event the Mail app dispatches after a flag change on the server. */
	private const FLAG_EVENT_CLASS = 'OCA\\Mail\\Events\\MessageFlaggedEvent';

	/** OCA\Mail\Events\MessageDeletedEvent: the event the Mail app dispatches for the source of a move. */
	private const DELETE_EVENT_CLASS = 'OCA\\Mail\\Events\\MessageDeletedEvent';

	/** OCA\Mail\Service\MailTransmission: the draft save (the class behind IMailTransmission). */
	private const TRANSMISSION_CLASS = 'OCA\\Mail\\Service\\MailTransmission';

	/** OCA\Mail\Model\NewMessageData: the message a draft save takes. */
	private const NEW_MESSAGE_DATA_CLASS = 'OCA\\Mail\\Model\\NewMessageData';

	/** OCA\Mail\Service\OutboxService: the Outbox and the send chain behind the Mail app's own send. */
	private const OUTBOX_SERVICE_CLASS = 'OCA\\Mail\\Service\\OutboxService';

	/** OCA\Mail\Db\LocalMessage: a message of the Outbox, and its status values. */
	private const LOCAL_MESSAGE_CLASS = 'OCA\\Mail\\Db\\LocalMessage';

	/** OCA\Mail\AddressList: recipients parsed as the Mail app parses them. */
	private const ADDRESS_LIST_CLASS = 'OCA\\Mail\\AddressList';

	/** OCA\Mail\IMAP\Search\Provider: the body search of the Mail app, an IMAP SEARCH that changes no flag. */
	private const SEARCH_PROVIDER_CLASS = 'OCA\\Mail\\IMAP\\Search\\Provider';

	/** OCA\Mail\Service\Search\SearchQuery: the filter value object the Mail mappers take. */
	private const SEARCH_QUERY_CLASS = 'OCA\\Mail\\Service\\Search\\SearchQuery';

	/** OCA\Mail\Service\Search\Flag: a flag condition of a search query. */
	private const FLAG_CLASS = 'OCA\\Mail\\Service\\Search\\Flag';

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
	 * OCA\Mail\Db\MessageMapper, used only through findIdsByQuery() and findByIds(), which read the message cache.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Db\MessageMapper
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function messageMapper(string $userId): object {
		return $this->resolve(self::MESSAGE_MAPPER_CLASS, $userId);
	}

	/**
	 * OCA\Mail\Service\MailManager, used only through getMessage(), getMailbox(), getThread() and getImapMessage().
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Service\MailManager
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function mailManager(string $userId): object {
		return $this->resolve(self::MAIL_MANAGER_CLASS, $userId);
	}

	/**
	 * OCA\Mail\IMAP\IMAPClientFactory, used only through getClient().
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\IMAP\IMAPClientFactory
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function clientFactory(string $userId): object {
		return $this->resolve(self::CLIENT_FACTORY_CLASS, $userId);
	}

	/**
	 * OCA\Mail\IMAP\MessageMapper, used only through getAttachment() on the client the caller already checked.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\IMAP\MessageMapper
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function imapMessageMapper(string $userId): object {
		return $this->resolve(self::IMAP_MESSAGE_MAPPER_CLASS, $userId);
	}

	/**
	 * The class name of OCA\Mail\Events\MessageFlaggedEvent, to dispatch it after a flag change as the Mail app does.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return string the class name
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function flagEventClass(string $userId): string {
		if (!$this->isEnabledFor($userId) || !class_exists(self::FLAG_EVENT_CLASS)) {
			throw new MailUnavailableException();
		}
		return self::FLAG_EVENT_CLASS;
	}

	/**
	 * The class name of OCA\Mail\Events\MessageDeletedEvent, to dispatch it for the source of a move as the Mail app does.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return string the class name
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function deleteEventClass(string $userId): string {
		if (!$this->isEnabledFor($userId) || !class_exists(self::DELETE_EVENT_CLASS)) {
			throw new MailUnavailableException();
		}
		return self::DELETE_EVENT_CLASS;
	}

	/**
	 * OCA\Mail\Service\MailTransmission, used only through saveDraft().
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Service\MailTransmission
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function transmission(string $userId): object {
		return $this->resolve(self::TRANSMISSION_CLASS, $userId);
	}

	/**
	 * A new OCA\Mail\Model\NewMessageData for a draft: plain text, no attachment, recipients parsed by
	 * OCA\Mail\AddressList::parse() as the Mail app parses a request.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @param object $account OCA\Mail\Account the user owns
	 * @param string $to recipients, comma separated
	 * @param string $cc copy recipients, comma separated
	 * @param string $bcc blind copy recipients, comma separated
	 * @param string $subject subject
	 * @param string $body plain text
	 * @return object OCA\Mail\Model\NewMessageData
	 * @throws MailUnavailableException when Mail is disabled for the user or a class is not loaded
	 */
	public function newMessageData(string $userId, object $account, string $to, string $cc, string $bcc, string $subject, string $body): object {
		$data = self::NEW_MESSAGE_DATA_CLASS;
		$list = self::ADDRESS_LIST_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($data) || !class_exists($list)) {
			throw new MailUnavailableException();
		}
		return new $data($account, $list::parse($to), $list::parse($cc), $list::parse($bcc), $subject, $body, [], false);
	}

	/**
	 * OCA\Mail\Service\OutboxService, used only through saveMessage() and sendMessage().
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Service\OutboxService
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function outboxService(string $userId): object {
		return $this->resolve(self::OUTBOX_SERVICE_CLASS, $userId);
	}

	/**
	 * A new outgoing OCA\Mail\Db\LocalMessage, as OutboxController::create() fills it for a plain text message: no send
	 * time, no alias, no reply, no signature or encryption.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @param int $accountId the account it is sent from
	 * @param string $subject subject
	 * @param string $body plain text
	 * @return object OCA\Mail\Db\LocalMessage
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function newOutgoingMessage(string $userId, int $accountId, string $subject, string $body): object {
		$class = self::LOCAL_MESSAGE_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		$message = new $class();
		$message->setType(constant($class . '::TYPE_OUTGOING'));
		$message->setAccountId($accountId);
		$message->setSubject($subject);
		$message->setBodyPlain($body);
		$message->setBodyHtml(null);
		$message->setHtml(false);
		$message->setSendAt(null);
		return $message;
	}

	/**
	 * The values of OCA\Mail\Db\LocalMessage status constants in the installed Mail; a name it lacks is left out.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @param list<string> $names constant names, such as STATUS_PROCESSED
	 * @return array<string, int> value by name
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function localMessageStatuses(string $userId, array $names): array {
		$class = self::LOCAL_MESSAGE_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		$values = [];
		foreach ($names as $name) {
			if (defined($class . '::' . $name)) {
				$values[$name] = (int)constant($class . '::' . $name);
			}
		}
		return $values;
	}

	/**
	 * OCA\Mail\IMAP\Search\Provider, used only through findMatches().
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\IMAP\Search\Provider
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function searchProvider(string $userId): object {
		return $this->resolve(self::SEARCH_PROVIDER_CLASS, $userId);
	}

	/**
	 * A new, empty OCA\Mail\Service\Search\SearchQuery.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Service\Search\SearchQuery
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function newSearchQuery(string $userId): object {
		$class = self::SEARCH_QUERY_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		return new $class();
	}

	/**
	 * A flag condition of a search query: Flag::is() or Flag::not() of one of the Mail flag constants.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @param string $constant name of the Flag constant: SEEN, FLAGGED or DELETED
	 * @param bool $isSet true for "has the flag", false for "has not"
	 * @return object OCA\Mail\Service\Search\Flag
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function flag(string $userId, string $constant, bool $isSet): object {
		$class = self::FLAG_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($class) || !defined($class . '::' . $constant)) {
			throw new MailUnavailableException();
		}
		$value = constant($class . '::' . $constant);
		return $isSet ? $class::is($value) : $class::not($value);
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
