<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\MailTasks;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Routines\Presentation\MailCardMessages;
use OCA\Mcp\Modules\Routines\Presentation\MailCardText;
use OCA\Mcp\Modules\Routines\RoutineStepGuard;
use OCA\Mcp\Platform\MailCards\DeckCardCreate;
use OCA\Mcp\Platform\MailCards\MailAttachmentSave;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use Psr\Log\LoggerInterface;

/**
 * The confirmed routines_create_mail_card: the attachment saved as a new file, then the card, once each (D4).
 *
 * Every read is done again first, without writing, and compared with the plan_state the user confirmed: a step the
 * actor may no longer run refuses the whole mail card, and anything that changed (the email, the folder, the board or
 * its readers) answers the new plan, before the file exists. Then each port checks again in its own sink. The card
 * starts only after the file was saved and read back, and carries the path and the private link of that file; when
 * the card does not succeed, the file stays and is reported. Nothing is retried, deleted or undone.
 */
final class CreateMailCardHandler {
	/**
	 * @param MailCardPlans $plans the reads of the three steps and their fingerprint
	 * @param CreateMailCardPreview $preview the plan answered when the state changed
	 * @param MailAttachmentSave $files the save, implemented by the Mail module
	 * @param DeckCardCreate $cards the card, implemented by the Deck module
	 * @param LoggerInterface $logger the class and code of an exception the card step threw ({@see RoutineStepGuard})
	 */
	public function __construct(
		private MailCardPlans $plans,
		private CreateMailCardPreview $preview,
		private MailAttachmentSave $files,
		private DeckCardCreate $cards,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @return array<string, mixed> the receipt as the result of the call; an error result only when nothing was written
	 * @throws PlanChanged when what the plan showed changed: nothing is written and the answer is the new plan
	 * @throws \InvalidArgumentException on a missing plan_state or a value a step refuses, before anything is written
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when a grant cannot be read now
	 */
	public function handle(string $userId, array $arguments): array {
		PlanState::require($arguments);
		$read = $this->plans->preflight($userId, $arguments);
		if (!isset($read['plans'])) {
			return self::result(MailCardReceipt::refused($read['step'], $read['message']), false);
		}
		$plans = $read['plans'];
		if (!PlanState::matches($this->plans->state($userId, $arguments, $plans), $arguments)) {
			$plan = $this->preview->view($userId, $arguments, $plans);
			$plan['notices'][] = MailCardMessages::planChanged();
			throw new PlanChanged($plan);
		}
		if (MailCardPlans::shared($plans) && !MailCardRequest::confirmShared($arguments)) {
			return self::result(MailCardReceipt::refused(($plans['file']['requiresSharedConfirmation'] ?? false) === true ? 'file' : 'card', MailCardMessages::sharedNeeded()), false);
		}
		$receipt = new MailCardReceipt();
		$file = $this->files->save($userId, MailCardRequest::messageId($arguments), MailCardRequest::attachmentId($arguments),
			MailCardRequest::path($arguments), MailCardRequest::confirmShared($arguments));
		if (!$receipt->record('file', $file)) {
			return self::result($receipt->build(), $file->status !== WorkflowStep::FAILED);
		}
		$link = is_string($file->result['url'] ?? null) ? $file->result['url'] : null;
		$description = MailCardText::description($plans['email']['message'], MailCardRequest::note($arguments), (string)$file->ids['path'], $link,
			MailCardRequest::excerptChars($arguments) > 0);
		$receipt->record('card', RoutineStepGuard::run($this->logger, [], fn (): WorkflowStep => $this->cards->createCard($userId, MailCardRequest::card($arguments, $description))));
		return self::result($receipt->build(), true);
	}

	/**
	 * A receipt after any write is a result, never an error a client could answer by calling again; only a mail card
	 * that wrote nothing is an error.
	 *
	 * @param array<string, mixed> $receipt the receipt of the mail card
	 * @param bool $written whether any step may have written
	 * @return array<string, mixed> the MCP result
	 */
	private static function result(array $receipt, bool $written): array {
		$result = ToolResult::json($receipt);
		return $written ? $result : $result + ['isError' => true];
	}
}
