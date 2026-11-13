<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\MailTasks;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Routines\Presentation\MailCardText;
use OCA\Mcp\Platform\MailCards\DeckCardCreate;
use OCA\Mcp\Platform\MailCards\MailAttachmentSave;
use OCA\Mcp\Platform\MailCards\MailCardSource;

/**
 * The three reads of a mail card through the Mail and Deck ports, never written: the email and its attachment, the
 * plan of the save and the plan of the card with the readers of its board. The plan of the tool shows them; the
 * confirmed call reads them again before anything is saved, and compares their fingerprint with the plan_state the
 * user confirmed, so a change of the email, the folder, the board or its readers asks for a new plan.
 */
final class MailCardPlans {
	/** The tool whose plans these are, so the fingerprint of one tool never confirms another. */
	public const TOOL = 'routines_create_mail_card';
	/** Keys of the save plan that describe what is saved and where, without the memory budget of the moment. */
	private const FILE_SHOWN = ['path', 'folderId', 'access', 'shared', 'requiresSharedConfirmation', 'attachmentId', 'name', 'declaredMimeType', 'declaredUpperBytes'];

	/**
	 * @param MailCardSource $source the message and the attachment, implemented by the Mail module
	 * @param MailAttachmentSave $files the attachment save, implemented by the Mail module
	 * @param DeckCardCreate $cards the card creation, implemented by the Deck module
	 * @param PlanState $states the fingerprint of what a plan showed
	 */
	public function __construct(
		private MailCardSource $source,
		private MailAttachmentSave $files,
		private DeckCardCreate $cards,
		private PlanState $states,
	) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return array{email: array<string, mixed>, file: array<string, mixed>, card: array<string, mixed>} the plan of each step
	 * @throws ToolFailure when a step is not allowed for the actor or would be refused
	 * @throws \InvalidArgumentException on a value a step refuses
	 */
	public function read(string $userId, array $arguments): array {
		$plans = [];
		foreach ($this->steps($userId, $arguments) as $step => $plan) {
			$plans[$step] = $plan($plans);
		}
		return $plans;
	}

	/**
	 * The preflight of the confirmed call: every read again, in order, before anything is written.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return array{plans: array{email: array<string, mixed>, file: array<string, mixed>, card: array<string, mixed>}}|array{step: string, message: string}
	 *     the plans, or the first step that would be refused and why
	 * @throws \InvalidArgumentException on a value a step refuses
	 */
	public function preflight(string $userId, array $arguments): array {
		$plans = [];
		foreach ($this->steps($userId, $arguments) as $step => $plan) {
			try {
				$plans[$step] = $plan($plans);
			} catch (ToolFailure $refused) {
				return ['step' => $step, 'message' => $refused->getMessage()];
			}
		}
		return ['plans' => $plans];
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @param array{email: array<string, mixed>, file: array<string, mixed>, card: array<string, mixed>} $plans the plans of {@see self::read()}
	 * @return string the fingerprint of everything the plan showed and the confirmed call depends on
	 */
	public function state(string $userId, array $arguments, array $plans): string {
		unset($arguments['confirm'], $arguments[PlanState::ARGUMENT]);
		$card = $plans['card'];
		unset($card['message']);
		return $this->states->of(self::TOOL, $userId, [
			'arguments' => $arguments,
			'email' => $plans['email'],
			'file' => array_intersect_key($plans['file'], array_flip(self::FILE_SHOWN)),
			'card' => $card,
		]);
	}

	/**
	 * @param array<string, mixed> $card a card plan of the Deck port
	 * @return array{destination: mixed, shared: mixed, audience: mixed} where the card lands and who can read it, the part
	 *     of the plan the card depends on besides its own text
	 */
	public static function board(array $card): array {
		return ['destination' => $card['destination'] ?? null, 'shared' => $card['shared'] ?? null, 'audience' => $card['audience'] ?? null];
	}

	/**
	 * @param array{file: array<string, mixed>, card: array<string, mixed>} $plans the plans of {@see self::read()}
	 * @return bool whether other people can reach the folder or the board: a folder of somebody else, a board of
	 *     somebody else, a board shared with anyone, or a board whose readers could not be read
	 */
	public static function shared(array $plans): bool {
		$audience = $plans['card']['audience'] ?? [];
		return ($plans['file']['requiresSharedConfirmation'] ?? false) === true
			|| ($plans['card']['shared'] ?? []) !== []
			|| ($audience['readable'] ?? false) !== true
			|| ($audience['entries'] ?? []) !== [];
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return array{email: \Closure(array): array<string, mixed>, file: \Closure(array): array<string, mixed>, card: \Closure(array): array<string, mixed>}
	 *     the read of each step, in order, each given the reads before it
	 */
	private function steps(string $userId, array $arguments): array {
		$excerpt = MailCardRequest::excerptChars($arguments);
		return [
			// The source always reads at least one character: max_chars of mail_read_message starts at 1.
			'email' => fn (array $done): array => $this->source->read($userId, MailCardRequest::messageId($arguments), MailCardRequest::attachmentId($arguments), max(1, $excerpt)),
			'file' => fn (array $done): array => $this->files->planSave($userId, MailCardRequest::messageId($arguments), MailCardRequest::attachmentId($arguments),
				MailCardRequest::path($arguments), MailCardRequest::confirmShared($arguments)),
			'card' => fn (array $done): array => $this->cards->planCard($userId, MailCardRequest::card($arguments,
				MailCardText::description($done['email']['message'], MailCardRequest::note($arguments), (string)($done['file']['path'] ?? MailCardRequest::path($arguments)), null, $excerpt > 0))),
		];
	}
}
