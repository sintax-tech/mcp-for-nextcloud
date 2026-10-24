<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mail\Exception\ClientException;
use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Exception/ClientException.php';

/**
 * A Mail app in memory, behind the same MailServices the module uses: accounts by owner, cached folders by account,
 * and a journal of every call the module makes into it. `find()` behaves like Mail 5.x: an account of another owner,
 * or none, throws ClientException.
 */
class FakeMailApp extends MailServices {
	/** @var \ArrayObject<int, string> every "Class::method" called on the fake app, in order */
	public \ArrayObject $journal;

	/** Whether the Mail app counts as enabled for the user. */
	public bool $enabled = true;

	/** @var list<array{id:int, name:string, email:string, owner:string}> stored accounts */
	private array $accounts = [];

	/** @var array<int, list<array<string, mixed>>> cached folders, by account id */
	private array $mailboxes = [];

	/**
	 * Builds an empty Mail app; the collaborators of the parent are stubs, since every method that used them is replaced.
	 *
	 * @return void
	 */
	public function __construct() {
		$stub = \Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class);
		parent::__construct($stub(IAppManager::class), $stub(ContainerInterface::class), $stub(IUserManager::class));
		$this->journal = new \ArrayObject();
	}

	/**
	 * Stores an account.
	 *
	 * @param int $id Mail account id
	 * @param string $owner UID of the owner
	 * @param string $name name of the account
	 * @param string $email address of the account
	 * @return self this app
	 */
	public function withAccount(int $id, string $owner, string $name, string $email): self {
		$this->accounts[] = ['id' => $id, 'name' => $name, 'email' => $email, 'owner' => $owner];
		return $this;
	}

	/**
	 * Stores a cached folder of an account.
	 *
	 * @param int $accountId Mail account id
	 * @param array<string, mixed> $fields id, name, delimiter, specialUse, unseen, messages, selectable
	 * @return self this app
	 */
	public function withMailbox(int $accountId, array $fields): self {
		$this->mailboxes[$accountId][] = $fields + ['delimiter' => '.', 'specialUse' => '[]', 'unseen' => 0, 'messages' => 0, 'selectable' => true];
		return $this;
	}

	/**
	 * Enablement as the test set it.
	 *
	 * @param string $userId authenticated user UID
	 * @return bool whether Mail counts as enabled
	 */
	public function isEnabledFor(string $userId): bool {
		return $this->enabled;
	}

	/**
	 * The account service, answering only findByUserId() and find().
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\Service\AccountService
	 * @throws MailUnavailableException when the test turned Mail off
	 */
	public function accountService(string $userId): object {
		$this->assertEnabled();
		return new RecordingDouble('AccountService', [
			'findByUserId' => fn (string $uid): array => array_map($this->account(...), array_values(array_filter($this->accounts, static fn (array $a): bool => $a['owner'] === $uid))),
			'find' => function (string $uid, int $id): object {
				foreach ($this->accounts as $account) {
					if ($account['owner'] === $uid && $account['id'] === $id) {
						return $this->account($account);
					}
				}
				throw new ClientException("Account $id does not exist or you don't have permission to access it");
			},
		], $this->journal);
	}

	/**
	 * The mailbox mapper, answering only findAll().
	 *
	 * @param string $userId authenticated user UID
	 * @return object strict double of OCA\Mail\Db\MailboxMapper
	 * @throws MailUnavailableException when the test turned Mail off
	 */
	public function mailboxMapper(string $userId): object {
		$this->assertEnabled();
		return new RecordingDouble('MailboxMapper', [
			'findAll' => fn (object $account): array => array_map($this->mailbox(...), $this->mailboxes[$account->getId()] ?? []),
		], $this->journal);
	}

	/**
	 * Every call the module made into the fake app, without the getters of the returned objects.
	 *
	 * @return list<string> "Class::method" in order
	 */
	public function serviceCalls(): array {
		return array_values(array_filter($this->journal->getArrayCopy(), static fn (string $call): bool => !str_starts_with($call, 'Account::') && !str_starts_with($call, 'Mailbox::')));
	}

	/**
	 * Throws like MailServices when Mail is off.
	 *
	 * @return void
	 * @throws MailUnavailableException when the test turned Mail off
	 */
	private function assertEnabled(): void {
		if (!$this->enabled) {
			throw new MailUnavailableException();
		}
	}

	/**
	 * A strict double of OCA\Mail\Account.
	 *
	 * @param array{id:int, name:string, email:string, owner:string} $row stored account
	 * @return object the double
	 */
	private function account(array $row): object {
		return new RecordingDouble('Account', [
			'getId' => static fn (): int => $row['id'],
			'getName' => static fn (): string => $row['name'],
			'getEmail' => static fn (): string => $row['email'],
			'getUserId' => static fn (): string => $row['owner'],
		], $this->journal);
	}

	/**
	 * A strict double of OCA\Mail\Db\Mailbox.
	 *
	 * @param array<string, mixed> $row stored folder
	 * @return object the double
	 */
	private function mailbox(array $row): object {
		return new RecordingDouble('Mailbox', [
			'getId' => static fn (): int => $row['id'],
			'getName' => static fn (): string => $row['name'],
			'getDelimiter' => static fn (): ?string => $row['delimiter'],
			'getSpecialUse' => static fn (): ?string => $row['specialUse'],
			'getUnseen' => static fn (): ?int => $row['unseen'],
			'getMessages' => static fn (): ?int => $row['messages'],
			'getSelectable' => static fn (): ?bool => $row['selectable'],
		], $this->journal);
	}
}
