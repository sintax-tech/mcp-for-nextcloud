<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\MailTasks;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Routines\Presentation\MailCardMessages;
use OCA\Mcp\Modules\Routines\Presentation\MailCardText;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesMessages;
use OCA\Mcp\Modules\Routines\RoutineStepGuard;
use OCA\Mcp\Platform\MailCards\DeckCardCreate;
use OCA\Mcp\Platform\MailCards\MailAttachmentSave;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use Psr\Log\LoggerInterface;

/**
 * The confirmed routines_create_mail_card: the attachment saved as a new file, then the card, once each (D4).
 *
 * Every read is done again first, without writing, and compared with the plan_state the user confirmed: a step the
 * actor may no longer run refuses the whole mail card, and anything that changed (the email, the folder, the board or
 * its readers) answers the new plan, before the file exists. Then each port checks again in its own sink. The card
 * starts only after the file was saved and read back, and only while the board and its readers are still what the
 * plan showed; it carries the path and the private link of that file. When the card does not start or does not
 * succeed, the file stays and is reported. Nothing is retried, deleted or undone.
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
		$file = $this->save($userId, $arguments);
		if (!$receipt->record('file', $file)) {
			return self::result($receipt->build(), $file->status !== WorkflowStep::FAILED);
		}
		$link = is_string($file->result['url'] ?? null) ? $file->result['url'] : null;
		$description = MailCardText::description($plans['email']['message'], MailCardRequest::note($arguments), (string)$file->ids['path'], $link,
			MailCardRequest::excerptChars($arguments) > 0);
		$card = MailCardRequest::card($arguments, $description);
		$receipt->record('card', $this->boardCheck($userId, $card, $plans['card'])
			?? RoutineStepGuard::run($this->logger, [], fn (): WorkflowStep => $this->cards->createCard($userId, $card)));
		return self::result($receipt->build(), true);
	}

	/**
	 * The save, the first write, with its error boundary: an exception that may come after the sink (a policy the
	 * read-back could not read, anything unexpected) is an uncertain file with the path it was saved to, so the receipt
	 * reaches the user and no card is created; a refusal of the arguments or a policy unreadable before any write
	 * leaves as it does everywhere else (-32602, 503).
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return WorkflowStep what the save observed, or the outcome of its exception
	 * @throws \InvalidArgumentException on a value the save refuses, before anything is written
	 * @throws PolicyUnavailable when a grant cannot be read before anything is written
	 */
	private function save(string $userId, array $arguments): WorkflowStep {
		try {
			return $this->files->save($userId, MailCardRequest::messageId($arguments), MailCardRequest::attachmentId($arguments),
				MailCardRequest::path($arguments), MailCardRequest::confirmShared($arguments));
		} catch (PolicyUnavailable $e) {
			if (!$e->uncertain) {
				throw $e;
			}
			$this->logger->warning('MCP routine step failed: {exception_detail}', ['app' => 'mcp', 'exception_detail' => ToolRegistry::loggable($e)]);
		} catch (\InvalidArgumentException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('MCP routine step failed: {exception_detail}', ['app' => 'mcp', 'exception_detail' => ToolRegistry::loggable($e)]);
		}
		return WorkflowStep::uncertain(WorkflowMessages::uncertain(), ['path' => MailCardRequest::path($arguments)]);
	}

	/**
	 * The board read again right before the card, after the file exists: who can read it, its owner and its list must
	 * be what the confirmed plan showed, or the card does not start. Nothing is written by this read.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $card the arguments of deck_create_card
	 * @param array<string, mixed> $planned the card plan the confirmed plan_state covers
	 * @return WorkflowStep|null null when the board is unchanged; otherwise the card step, refused before its write
	 */
	private function boardCheck(string $userId, array $card, array $planned): ?WorkflowStep {
		try {
			$now = $this->cards->planCard($userId, $card);
		} catch (ToolFailure $refused) {
			return WorkflowStep::failed($refused->getMessage());
		} catch (\Throwable $e) {
			$this->logger->warning('MCP routine step failed: {exception_detail}', ['app' => 'mcp', 'exception_detail' => ToolRegistry::loggable($e)]);
			return WorkflowStep::failed($e instanceof PolicyUnavailable ? RoutinesMessages::stepPolicyUnavailable() : RoutinesMessages::stepRefused());
		}
		return MailCardPlans::board($now) === MailCardPlans::board($planned) ? null : WorkflowStep::failed(MailCardMessages::boardChanged());
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
