<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

use OCA\Mcp\Access\Grants\GrantPolicy;

/**
 * Addresses carried as data on the occurrence. A name in the summary or the location is not an address and not a UID.
 */
final class ParticipantRoster {
	/**
	 * @param GrantPolicy $grants the actor's grants
	 * @param AccountEmailMatch $accounts exact address to one local account
	 */
	public function __construct(
		private GrantPolicy $grants,
		private AccountEmailMatch $accounts,
	) {
	}

	/**
	 * @param array<string, mixed> $event the chosen occurrence
	 * @param string $userId the actor
	 * @return array{participants:list<array<string, mixed>>, truncated:bool}
	 */
	public function fromEvent(array $event, string $userId): array {
		$emails = [];
		foreach ((array)($event['attendees'] ?? []) as $attendee) {
			$email = $this->emailOf($attendee);
			if ($email !== null && !in_array($email, $emails, true)) {
				$emails[] = $email;
			}
		}
		$truncated = count($emails) > DossierBudget::MAX_PARTICIPANTS;
		$kept = array_slice($emails, 0, DossierBudget::MAX_PARTICIPANTS);
		$people = $this->grants->granted($userId, 'people', 'read');
		$rows = [];
		foreach ($kept as $email) {
			$rows[] = [
				'email' => $email,
				'uid' => $people ? $this->accounts->soleUid($email) : null,
				'reason' => 'event_attendee',
				'account' => $people ? 'exact_or_none' : 'not_consulted',
			];
		}
		return ['participants' => $rows, 'truncated' => $truncated];
	}

	/**
	 * @param mixed $attendee one attendee value from the occurrence
	 * @return string|null the address, when the value is one
	 */
	private function emailOf(mixed $attendee): ?string {
		if (is_string($attendee)) {
			return ExactEmail::normalize($attendee);
		}
		if (is_array($attendee) && isset($attendee['email'])) {
			return ExactEmail::normalize((string)$attendee['email']);
		}
		return null;
	}
}
