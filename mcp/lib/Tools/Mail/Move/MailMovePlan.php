<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Move;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Tools\Mail\MailCacheGeneration;
use OCA\Mcp\Tools\Mail\MailMessageRows;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\Mail\MailRows;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/**
 * The plan of a move, built from what the Mail app cached, without contacting the mail server, and its
 * {@see PlanState}.
 *
 * The plan names the message, the folder it leaves and the folder it goes to by their ids; the account address and both
 * folder paths only for a user with `mail.read`, the subject, sender and date only with `mail.messages`, so the move grant
 * alone never reads a message through a plan. Subjects and names are the senders' text, marked untrusted.
 *
 * The plan state covers the message's identity as cached (message, folder and account ids, UID, the source folder's UID
 * generation, the Message-ID) and the destination as stored (its id, path, kind and, for the Trash, the retention the
 * plan warned of): a confirmed call whose cached message, destination or warning differs, or whose arguments do, gets
 * the new plan instead of a move, so a folder that became the Trash, or a retention that changed, is never confirmed
 * silently.
 */
class MailMovePlan {
	/** MCP tool name of the plan. */
	public const TOOL = 'mail_move_message';

	/**
	 * Receives the plan states, the grants and the row shaper.
	 *
	 * @param PlanState $states the instance's plan states
	 * @param GrantPolicy $grants who may read what, for the identifying fields
	 * @param MailMessageRows $rows shapes the cached message fields
	 * @return void
	 */
	public function __construct(
		private PlanState $states,
		private GrantPolicy $grants,
		private MailMessageRows $rows,
	) {
	}

	/**
	 * The plan.
	 *
	 * @param string $userId authenticated user UID
	 * @param array{message:object, mailbox:object, account:object} $found the user's own message, folder and account
	 * @param array{mailbox:object, kind:string, retentionDays:int|null} $destination the destination, its kind and the Trash retention
	 * @param bool $changed whether a confirmed call found this instead of the approved plan: a warning says so first
	 * @return array<string, mixed> the plan
	 */
	public function plan(string $userId, array $found, array $destination, bool $changed = false): array {
		$readsFolders = $this->grants->granted($userId, MailModule::MODULE, 'read');
		$from = ['mailboxId' => (int)$found['mailbox']->getId()];
		$to = ['mailboxId' => (int)$destination['mailbox']->getId()];
		$target = ['messageId' => (int)$found['message']->getId(), 'accountId' => (int)$found['account']->getId()];
		if ($readsFolders) {
			$target['account'] = MailRows::text((string)$found['account']->getEmail());
			$from['path'] = MailRows::text((string)$found['mailbox']->getName());
			$to['path'] = MailRows::text((string)$destination['mailbox']->getName());
		}
		$untrusted = $this->grants->granted($userId, MailModule::MODULE, 'messages');
		if ($untrusted) {
			$summary = $this->rows->summary($found['message'], $target['accountId']);
			$target += ['subject' => $summary['subject'], 'from' => $summary['from'], 'date' => $summary['date']];
		}
		$notes = match ($destination['kind']) {
			MailMoveDestination::SAME => [MailMoveMessages::alreadyThere()],
			MailMoveDestination::JUNK => [MailMoveMessages::junkNote()],
			MailMoveDestination::TRASH => [MailMoveMessages::trashNote($destination['retentionDays'])],
			default => [],
		};
		return [
			'action' => self::TOOL,
			'target' => $target,
			'from' => $from,
			'to' => $to + ['kind' => $destination['kind']] + ($destination['kind'] === MailMoveDestination::TRASH ? ['retentionDays' => $destination['retentionDays']] : []),
			'moves' => $destination['kind'] !== MailMoveDestination::SAME,
			'message' => $destination['kind'] === MailMoveDestination::SAME ? MailMoveMessages::alreadyThere() : CommonMessages::planNothingChanged(),
			PlanState::ARGUMENT => $this->state($userId, $found, $destination),
		] + ($notes === [] ? [] : ['notes' => $notes])
			+ ($untrusted ? ['untrustedMailData' => true, 'notice' => MailMessages::UNTRUSTED_NOTICE] : [])
			+ ($changed ? ['warnings' => [['message' => MailMoveMessages::planChanged()]]] : []);
	}

	/**
	 * The plan state of a message and a destination.
	 *
	 * @param string $userId authenticated user UID
	 * @param array{message:object, mailbox:object, account:object} $found the user's own message, folder and account
	 * @param array{mailbox:object, kind:string, retentionDays:int|null} $destination the destination, its kind and the Trash retention
	 * @return string the state
	 */
	public function state(string $userId, array $found, array $destination): string {
		$message = $found['message'];
		return $this->states->of(self::TOOL, $userId, [
			'message' => (int)$message->getId(),
			'mailbox' => (int)$found['mailbox']->getId(),
			'account' => (int)$found['account']->getId(),
			'uid' => (int)$message->getUid(),
			'generation' => MailCacheGeneration::of($found['mailbox']),
			'messageIdHeader' => $message->getMessageId() === null ? null : (string)$message->getMessageId(),
			'destination' => (int)$destination['mailbox']->getId(),
			'destinationPath' => (string)$destination['mailbox']->getName(),
			'kind' => $destination['kind'],
			'retentionDays' => $destination['retentionDays'],
		]);
	}
}
