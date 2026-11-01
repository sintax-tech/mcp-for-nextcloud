<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Draft;

use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Saves one new draft through the Mail app's own draft save, IMailTransmission::saveDraft(), with no previous draft.
 *
 * That is the save behind the Mail app's AccountsController::draft(), the same in every supported release: it builds the
 * message with Horde_Mail_Transport_Null (no SMTP), appends it with \Draft to the account's Drafts folder on one IMAP client
 * of its own and dispatches DraftSavedEvent, whose listener deletes a previous draft only when one is given (never here).
 * It writes no LocalMessage, so nothing reaches the Mail app's Outbox, its send queue or SMTP. The other draft path,
 * DraftsService::saveMessage(), stores a LocalMessage that a background job moves later and is not used.
 *
 * A ClientException is the Mail app's refusal before anything is written (no Drafts folder configured): no draft. Any
 * other failure may come after the append (the answer lost, the event failing), so it is reported as uncertain. This class
 * calls the save once and adds no retry; the fallbacks inside the Mail app and Horde stay as they are (Horde's append,
 * for one, logs in again and appends once more after a disconnection while BINARY was in use), so one confirmation is
 * not guaranteed to append exactly once when the connection fails. The tool keeps no receipt, so a repeated confirmation
 * creates another draft.
 *
 * The UID is the one the save returns when it is a positive integer: the Mail app turns a UID set it could not read into
 * 0 (IMAP\MessageMapper::save() casts the first UID of an empty set), which is no IMAP UID, so 0 or less is reported as
 * unknown while the draft still counts as created.
 */
class MailDraftStore {
	/**
	 * Receives the Mail classes.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
	) {
	}

	/**
	 * Saves the draft.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @param MailDraftRequest $draft the draft asked for
	 * @return int|null the UID of the new draft in the Drafts folder, or null when the save gives none, or none that is
	 *     a positive integer
	 * @throws ToolFailure when no draft was created, or the save has no known outcome
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function create(string $userId, object $account, MailDraftRequest $draft): ?int {
		$transmission = $this->services->transmission($userId);
		try {
			$data = $this->services->newMessageData($userId, $account, $draft->joined('to'), $draft->joined('cc'), $draft->joined('bcc'), $draft->subject, $draft->body);
		} catch (MailUnavailableException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// Building the message touches no server.
			throw new ToolFailure(MailDraftMessages::notCreated(), 0, $e);
		}
		$refusal = MailServices::CLIENT_EXCEPTION_CLASS;
		try {
			$saved = $transmission->saveDraft($data, null);
		} catch (\Throwable $e) {
			// Never the exception's message, which can carry mailbox data.
			throw new ToolFailure($e instanceof $refusal ? MailDraftMessages::notCreated() : MailDraftMessages::uncertain(), 0, $e);
		}
		$uid = is_array($saved) ? ($saved[2] ?? null) : null;
		return is_int($uid) && $uid > 0 ? $uid : null;
	}
}
