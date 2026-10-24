<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

/**
 * Thin read-only boundary to the Mail app: turns its accounts and cached folders into plain arrays, so the handlers
 * never hold a Mail object and are tested with doubles.
 *
 * Only three Mail calls are made, and each one reads the database of the Mail app without contacting the mail server
 * (checked in the source of every Mail release MCP supports, 5.0.0 to 5.12.5):
 * - `AccountService::findByUserId()` reads the accounts through `MailAccountMapper::findByUserId()`, a SELECT on
 *   `mail_accounts` filtered by owner;
 * - `AccountService::find()` reads one account through `MailAccountMapper::find()`, a SELECT filtered by owner and id,
 *   and throws a ClientException for any other account;
 * - `MailboxMapper::findAll()` is a SELECT on `mail_mailboxes` by account id.
 * `MailManager::getMailboxes()` is deliberately not used: it runs `MailboxSync::sync()` first, which logs in to the
 * IMAP server. Nothing that flags, moves, syncs or writes is ever reached from here.
 *
 * Accounts delegated to the user by somebody else are left out: an account counts only when its owner is the user,
 * whatever a future Mail release returns from these calls.
 */
class MailGateway {
	/**
	 * Receives the resolver of the Mail services.
	 *
	 * @param MailServices $services the Mail classes, resolved by name after the per-user enablement check
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
	) {
	}

	/**
	 * The mail accounts the user owns, as stored by the Mail app.
	 *
	 * @param string $userId authenticated user UID
	 * @return list<array{id:int, name:string, email:string}> one row per owned account, in the order of the Mail app
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function accounts(string $userId): array {
		$rows = [];
		foreach ($this->services->accountService($userId)->findByUserId($userId) as $account) {
			if ((string)$account->getUserId() !== $userId) {
				continue;
			}
			$rows[] = $this->accountRow($account);
		}
		return $rows;
	}

	/**
	 * One account the user owns, by id.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $accountId Mail account id
	 * @return object|null the OCA\Mail\Account; null when it does not exist, belongs to somebody else or is delegated
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function ownedAccount(string $userId, int $accountId): ?object {
		$exception = MailServices::CLIENT_EXCEPTION_CLASS;
		try {
			$account = $this->services->accountService($userId)->find($userId, $accountId);
		} catch (\Exception $e) {
			if ($e instanceof $exception) {
				return null;
			}
			throw $e;
		}
		return (string)$account->getUserId() === $userId ? $account : null;
	}

	/**
	 * The folders of an account, from the cache of the Mail app; empty when Mail has not synchronized them yet.
	 *
	 * @param string $userId authenticated user UID, for the enablement check
	 * @param object $account the OCA\Mail\Account returned by {@see self::ownedAccount()}
	 * @return list<array{id:int, name:string, delimiter:string|null, specialUse:string, unread:int|null, total:int|null, selectable:bool|null}> one row per cached folder
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function mailboxes(string $userId, object $account): array {
		$rows = [];
		foreach ($this->services->mailboxMapper($userId)->findAll($account) as $mailbox) {
			$rows[] = [
				'id' => (int)$mailbox->getId(),
				'name' => (string)$mailbox->getName(),
				'delimiter' => $mailbox->getDelimiter() === null ? null : (string)$mailbox->getDelimiter(),
				'specialUse' => (string)($mailbox->getSpecialUse() ?? '[]'),
				'unread' => $mailbox->getUnseen() === null ? null : (int)$mailbox->getUnseen(),
				'total' => $mailbox->getMessages() === null ? null : (int)$mailbox->getMessages(),
				'selectable' => $mailbox->getSelectable() === null ? null : (bool)$mailbox->getSelectable(),
			];
		}
		return $rows;
	}

	/**
	 * The fields of an account that identify it to the user: never a password, a token, a server or a port.
	 *
	 * @param object $account OCA\Mail\Account
	 * @return array{id:int, name:string, email:string} the row of the account
	 */
	public function accountRow(object $account): array {
		return [
			'id' => (int)$account->getId(),
			'name' => (string)$account->getName(),
			'email' => (string)$account->getEmail(),
		];
	}
}
