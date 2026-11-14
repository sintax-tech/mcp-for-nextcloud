<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\MailTasks;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Routines\Presentation\MailCardMessages;

/**
 * The plan of routines_create_mail_card: the email it reads, the file it saves and the exact card it creates, with the
 * readers of the board and what of the email the card copies there. Nothing is read beyond metadata and the asked
 * excerpt, and nothing is written; the link of the file is only known after the file exists.
 */
final class CreateMailCardPreview {
	/** Keys of a step plan that belong to the confirmation of its own tool, not to this one. */
	private const OWN_CONFIRMATION = ['requiresConfirmation' => true, 'message' => true];

	/** @param MailCardPlans $plans the reads of the three steps */
	public function __construct(private MailCardPlans $plans) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments, without confirm
	 * @return array<string, mixed> the plan of the mail card, with its plan_state
	 * @throws ToolFailure when a step is not allowed for the actor or would be refused
	 * @throws \InvalidArgumentException on a value a step refuses
	 */
	public function plan(string $userId, array $arguments): array {
		return $this->view($userId, $arguments, $this->plans->read($userId, $arguments));
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @param array{email: array<string, mixed>, file: array<string, mixed>, card: array<string, mixed>} $plans the reads of {@see MailCardPlans}
	 * @return array<string, mixed> the plan the user confirms, the same for the preview and for a plan that changed
	 */
	public function view(string $userId, array $arguments, array $plans): array {
		$card = array_diff_key($plans['card'], self::OWN_CONFIRMATION);
		$audience = $card['audience'] ?? ['readable' => false];
		unset($card['audience']);
		return [
			'action' => MailCardPlans::TOOL,
			'message' => MailCardMessages::planPrompt(),
			'email' => $plans['email'],
			'steps' => [
				['step' => 'file', 'tool' => 'mail_save_attachment', 'plan' => array_diff_key($plans['file'], self::OWN_CONFIRMATION)],
				['step' => 'card', 'tool' => 'deck_create_card', 'plan' => $card, 'file_link' => ['value' => null]],
			],
			'board_readers' => $audience,
			'notices' => $this->notices($arguments, $plans, $audience),
			PlanState::ARGUMENT => $this->plans->state($userId, $arguments, $plans),
		];
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @param array{file: array<string, mixed>, card: array<string, mixed>} $plans the reads of {@see MailCardPlans}
	 * @param array<string, mixed> $audience who can read the board
	 * @return list<string> what the user has to know before confirming
	 */
	private function notices(array $arguments, array $plans, array $audience): array {
		$notices = [MailCardMessages::fileLinkPending(), MailCardMessages::privateFile(), MailCardMessages::stopsAtFirstFailure()];
		if (($audience['readable'] ?? false) !== true) {
			$notices[] = MailCardMessages::audienceUnknown();
		}
		if (array_filter((array)($audience['entries'] ?? []), static fn (array $entry): bool => $entry['type'] !== 'user') !== []) {
			$notices[] = MailCardMessages::groupsAreOpen();
		}
		if (MailCardPlans::shared($plans)) {
			$notices[] = MailCardRequest::excerptChars($arguments) > 0 ? MailCardMessages::disclosureExcerpt() : MailCardMessages::disclosure();
			if (!MailCardRequest::confirmShared($arguments)) {
				$notices[] = MailCardMessages::sharedNeeded();
			}
		}
		return $notices;
	}
}
