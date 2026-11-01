<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Flag;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Tools\Mail\MailCacheGeneration;
use OCA\Mcp\Tools\Mail\MailMessageRows;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\Mail\MailRows;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/**
 * The plan of a flag change, built from what the Mail app cached, without contacting the mail server, and its
 * {@see PlanState}.
 *
 * The plan names the message by its ids, its account and folder and the changes from the cached state to the asked one.
 * What identifies it further is shown only to a user who may read it anyway: the account name, address and folder path
 * with `mail.read`, the subject, sender and date with `mail.messages`. The flags grant alone therefore never reads a
 * message through a plan. Subjects and names are the senders' text, marked untrusted.
 *
 * The plan state covers the message's identity as cached (message, folder and account ids, UID, the folder's UID
 * generation, the Message-ID) and the asked flags: a confirmed call whose cached message or arguments differ gets the new
 * plan instead of a change. The cached flags stay out of it, since the call asks for a state, not a toggle: a flag set
 * meanwhile by somebody else is simply left as asked.
 */
class MailFlagPlan {
	/** MCP tool name of the plan. */
	public const TOOL = 'mail_set_flags';

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
	 * @param MailFlagRequest $request the asked state
	 * @param bool $changed whether a confirmed call found this instead of the approved plan: a warning says so first
	 * @return array<string, mixed> the plan
	 */
	public function plan(string $userId, array $found, MailFlagRequest $request, bool $changed = false): array {
		$message = $found['message'];
		$current = ['seen' => $message->getFlagSeen(), 'flagged' => $message->getFlagFlagged()];
		$changes = $request->changes($current);
		$target = [
			'messageId' => (int)$message->getId(),
			'accountId' => (int)$found['account']->getId(),
			'mailboxId' => (int)$found['mailbox']->getId(),
		];
		if ($this->grants->granted($userId, MailModule::MODULE, 'read')) {
			$target['account'] = MailRows::text((string)$found['account']->getEmail());
			$target['folder'] = MailRows::text((string)$found['mailbox']->getName());
		}
		$untrusted = $this->grants->granted($userId, MailModule::MODULE, 'messages');
		if ($untrusted) {
			$summary = $this->rows->summary($message, $target['accountId']);
			$target += ['subject' => $summary['subject'], 'from' => $summary['from'], 'date' => $summary['date']];
		}
		$warnings = $changed ? [['message' => MailFlagMessages::planChanged()]] : [];
		return [
			'action' => self::TOOL,
			'target' => $target,
			'changes' => array_map(static fn (string $flag, bool $set): array => [
				'flag' => $flag,
				'from' => $current[$flag] === null ? null : MailFlagMessages::state($flag, (bool)$current[$flag]),
				'to' => MailFlagMessages::state($flag, $set),
			], array_keys($changes), $changes),
			'unchanged' => array_map(static fn (string $flag): array => ['flag' => $flag, 'state' => MailFlagMessages::state($flag, $request->wanted[$flag])],
				array_keys(array_diff_key($request->wanted, $changes))),
			'message' => $changes === [] ? MailFlagMessages::alreadySet() : CommonMessages::planNothingChanged(),
			PlanState::ARGUMENT => $this->state($userId, $found, $request),
		] + ($untrusted ? ['untrustedMailData' => true, 'notice' => MailMessages::UNTRUSTED_NOTICE] : [])
			+ ($warnings === [] ? [] : ['warnings' => $warnings]);
	}

	/**
	 * The plan state of a message and a request.
	 *
	 * @param string $userId authenticated user UID
	 * @param array{message:object, mailbox:object, account:object} $found the user's own message, folder and account
	 * @param MailFlagRequest $request the asked state
	 * @return string the state
	 */
	public function state(string $userId, array $found, MailFlagRequest $request): string {
		$message = $found['message'];
		return $this->states->of(self::TOOL, $userId, [
			'message' => (int)$message->getId(),
			'mailbox' => (int)$found['mailbox']->getId(),
			'account' => (int)$found['account']->getId(),
			'uid' => (int)$message->getUid(),
			'generation' => MailCacheGeneration::of($found['mailbox']),
			'messageIdHeader' => $message->getMessageId() === null ? null : (string)$message->getMessageId(),
			'wanted' => $request->wanted,
		]);
	}
}
