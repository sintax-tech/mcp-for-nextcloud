<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Meetings\MeetingMailRead;
use OCA\Mcp\Platform\Meetings\PreparationSources;

/**
 * Messages of the actor whose from, to or cc equals a participant address.
 *
 * Each field is a separate search, because the mail reader treats different fields as AND. The fragment is the
 * address itself; a hit whose address is only a longer fragment is dropped. No body search and no other mailbox.
 * At most {@see DossierBudget::MAX_FOLDERS} folders are searched, the inbox first and then the reader's order.
 * Folders beyond that are partial coverage. They are not a reader failure.
 */
final class MailCandidates {
	/** Fields searched one at a time so a match in any of them counts. */
	private const FIELDS = ['from', 'to', 'cc'];

	/**
	 * @param PreparationSources $sources the read ports
	 * @param GrantPolicy $grants the actor's grants
	 */
	public function __construct(
		private PreparationSources $sources,
		private GrantPolicy $grants,
	) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $event the chosen occurrence
	 * @param list<array<string, mixed>> $participants addresses kept for this dossier
	 * @return array<string, mixed> the mail source, with no count of what was not read
	 */
	public function collect(string $userId, array $event, array $participants): array {
		$mail = $this->sources->mail();
		if ($mail === null) {
			return $this->closed('unavailable', 'unbound');
		}
		if (!$this->grants->granted($userId, 'mail', 'messages')) {
			return $this->closed('not_consulted', 'grant');
		}
		$emails = array_values(array_filter(array_map(static fn (array $row): string => (string)$row['email'], $participants)));
		if ($emails === []) {
			return $this->closed('consulted', 'no_address');
		}
		$window = $this->window($event);
		if ($window === null) {
			return $this->closed('not_consulted', 'no_window');
		}
		return $this->search($mail, $userId, $emails, $window);
	}

	/**
	 * @param MeetingMailRead $mail the mail port
	 * @param string $userId the actor
	 * @param list<string> $emails participant addresses
	 * @param array{since:string, until:string} $window the 30 days before the event
	 * @return array<string, mixed> the mail source
	 */
	private function search(MeetingMailRead $mail, string $userId, array $emails, array $window): array {
		try {
			$folderIds = $mail->folders($userId);
		} catch (ToolFailure) {
			return $this->closed('unavailable', 'reader');
		}
		$foldersCut = count($folderIds) > DossierBudget::MAX_FOLDERS;
		$folderIds = array_slice($folderIds, 0, DossierBudget::MAX_FOLDERS);
		$kept = [];
		$seen = [];
		$truncated = $foldersCut;
		try {
			foreach ($emails as $email) {
				foreach (self::FIELDS as $field) {
					foreach ($folderIds as $mailboxId) {
						if (count($kept) >= DossierBudget::MAX_MESSAGES) {
							$truncated = true;
							break 3;
						}
						$page = ResultJson::object($mail->search($userId, [
							'mailbox_id' => $mailboxId,
							$field => [$email],
							'since' => $window['since'],
							'until' => $window['until'],
							'limit' => DossierBudget::MAX_MESSAGES,
						]));
						foreach ((array)($page['messages'] ?? []) as $message) {
							if (!is_array($message) || (int)($message['mailboxId'] ?? 0) !== $mailboxId) {
								continue;
							}
							$row = $this->exact($message, $email, $field);
							if ($row === null || isset($seen[$row['id']])) {
								continue;
							}
							if (count($kept) >= DossierBudget::MAX_MESSAGES) {
								$truncated = true;
								break 4;
							}
							$seen[$row['id']] = true;
							$kept[] = $row;
						}
						if (($page['hasMore'] ?? false) === true) {
							$truncated = true;
						}
					}
				}
			}
		} catch (ToolFailure) {
			return $this->closed('unavailable', 'reader');
		}
		return ['status' => 'consulted', 'reason' => $foldersCut ? 'folder_budget' : 'exact_address', 'truncated' => $truncated, 'candidates' => $kept];
	}

	/**
	 * @param array<string, mixed> $message one message summary
	 * @param string $email the participant address
	 * @param string $field from, to or cc
	 * @return array<string, mixed>|null the candidate when that field holds the exact address
	 */
	private function exact(array $message, string $email, string $field): ?array {
		foreach ((array)($message[$field]['addresses'] ?? []) as $address) {
			if (!is_array($address) || !ExactEmail::same((string)($address['email'] ?? ''), $email)) {
				continue;
			}
			return [
				'source' => 'mail',
				'reason' => 'exact_' . $field,
				'email' => $email,
				'id' => (int)($message['id'] ?? 0),
				'subject' => mb_substr((string)($message['subject'] ?? ''), 0, DossierBudget::EXCERPT),
				'date' => (string)($message['date'] ?? ''),
				'url' => (string)($message['url'] ?? ''),
			];
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $event the chosen occurrence
	 * @return array{since:string, until:string}|null the lookback, or null when the start is not a date
	 */
	private function window(array $event): ?array {
		try {
			$start = new \DateTimeImmutable((string)$event['start']);
		} catch (\Exception) {
			return null;
		}
		return [
			'since' => $start->sub(new \DateInterval('P' . DossierBudget::LOOKBACK_DAYS . 'D'))->format('Y-m-d'),
			'until' => $start->format('Y-m-d'),
		];
	}

	/**
	 * @param string $status consulted, not_consulted or unavailable
	 * @param string $reason why, with no count of hidden objects
	 * @return array<string, mixed> a source that holds no messages
	 */
	private function closed(string $status, string $reason): array {
		return ['status' => $status, 'reason' => $reason, 'truncated' => false, 'candidates' => []];
	}
}
