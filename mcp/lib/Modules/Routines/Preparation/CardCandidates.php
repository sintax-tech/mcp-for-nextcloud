<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Meetings\MeetingDeckRead;
use OCA\Mcp\Platform\Meetings\PreparationSources;

/**
 * Cards named by an explicit Deck URL or by an assignment of a resolved account, and the file paths their links carry.
 *
 * There is no search by title. A card the reader refuses is omitted, with no count of those refusals.
 */
final class CardCandidates {
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
	 * @param list<array<string, mixed>> $participants accounts already resolved, used only when uid is set
	 * @return array{report: array<string, mixed>, paths: list<string>, references: list<string>} the Deck source, and paths and file references
	 */
	public function collect(string $userId, array $event, array $participants = []): array {
		$deck = $this->sources->deck();
		if ($deck === null) {
			return $this->emptyReport('unavailable', 'unbound');
		}
		if (!$this->grants->granted($userId, 'deck', 'read')) {
			return $this->emptyReport('not_consulted', 'grant');
		}
		[$ids, $reasons] = $this->ids($deck, $userId, $event, $participants);
		if ($ids === []) {
			return $this->emptyReport('consulted', 'no_card');
		}
		return $this->read($deck, $userId, $ids, $reasons);
	}

	/**
	 * @param MeetingDeckRead $deck the Deck port
	 * @param string $userId the actor
	 * @param list<int> $ids positive card ids
	 * @param array<int, string> $reasons card id => event_card or assigned
	 * @return array{report: array<string, mixed>, paths: list<string>, references: list<string>} the Deck source and the linked files
	 */
	private function read(MeetingDeckRead $deck, string $userId, array $ids, array $reasons): array {
		$truncated = count($ids) > DossierBudget::MAX_CARDS;
		$kept = [];
		$paths = [];
		$references = [];
		foreach (array_slice($ids, 0, DossierBudget::MAX_CARDS) as $id) {
			try {
				$card = ResultJson::object($deck->card($userId, ['cardId' => $id]));
				$links = ResultJson::object($deck->links($userId, ['cardId' => $id]));
			} catch (ToolFailure | \InvalidArgumentException) {
				continue;
			}
			$kept[] = [
				'source' => 'deck',
				'reason' => $reasons[$id] ?? 'event_card',
				'cardId' => $id,
				'title' => mb_substr((string)($card['title'] ?? ''), 0, DossierBudget::EXCERPT),
				'url' => is_string($card['url'] ?? null) ? $card['url'] : '',
			];
			foreach ((array)($links['items'] ?? []) as $item) {
				$path = $this->pathOf($item);
				if ($path !== null) {
					$paths[] = $path;
				}
			}
			$encoded = json_encode([$card, $links], JSON_INVALID_UTF8_SUBSTITUTE);
			if (is_string($encoded)) {
				foreach (ReferenceTexts::fileReferences($encoded) as $reference) {
					$references[] = $reference;
				}
			}
		}
		$reason = $kept === [] ? 'event_card' : (in_array('event_card', $reasons, true) ? 'event_card' : 'assigned');
		return [
			'report' => ['status' => 'consulted', 'reason' => $reason, 'truncated' => $truncated, 'candidates' => $kept],
			'paths' => $paths,
			'references' => $references,
		];
	}

	/**
	 * @param MeetingDeckRead $deck the Deck port
	 * @param string $userId the actor
	 * @param array<string, mixed> $event the chosen occurrence
	 * @param list<array<string, mixed>> $participants resolved accounts
	 * @return array{0: list<int>, 1: array<int, string>} card ids and the reason of each
	 */
	private function ids(MeetingDeckRead $deck, string $userId, array $event, array $participants): array {
		$ids = [];
		$reasons = [];
		foreach ((array)($event['cardIds'] ?? []) as $id) {
			$this->add($ids, $reasons, $id, 'event_card');
		}
		foreach ((array)($event['links'] ?? []) as $link) {
			if (!is_string($link)) {
				continue;
			}
			foreach (ReferenceTexts::deckCardIds($link) as $id) {
				$this->add($ids, $reasons, $id, 'event_card');
			}
		}
		foreach ($participants as $row) {
			$uid = is_array($row) ? ($row['uid'] ?? null) : null;
			if (!is_string($uid) || $uid === '') {
				continue;
			}
			try {
				foreach ($deck->assigned($userId, $uid) as $id) {
					$this->add($ids, $reasons, $id, 'assigned');
				}
			} catch (ToolFailure | \InvalidArgumentException) {
				continue;
			}
		}
		return [$ids, $reasons];
	}

	/**
	 * @param list<int> $ids ids kept so far
	 * @param array<int, string> $reasons the reason of each id
	 * @param mixed $id a candidate id
	 * @param string $reason event_card or assigned
	 */
	private function add(array &$ids, array &$reasons, mixed $id, string $reason): void {
		if (!is_int($id) || $id <= 0 || isset($reasons[$id])) {
			return;
		}
		$ids[] = $id;
		$reasons[$id] = $reason;
	}

	/**
	 * @param mixed $item one link the Deck reader returned
	 * @return string|null a path the reader already named
	 */
	private function pathOf(mixed $item): ?string {
		if (!is_array($item)) {
			return null;
		}
		$path = $item['path'] ?? (is_array($item['file'] ?? null) ? ($item['file']['path'] ?? null) : null);
		return is_string($path) && $path !== '' ? $path : null;
	}

	/**
	 * @param string $status consulted, not_consulted or unavailable
	 * @param string $reason why, with no count of hidden objects
	 * @return array<string, mixed> a Deck source that holds no cards
	 */
	private function closed(string $status, string $reason): array {
		return ['status' => $status, 'reason' => $reason, 'truncated' => false, 'candidates' => []];
	}

	/**
	 * @param string $status consulted, not_consulted or unavailable
	 * @param string $reason why, with no count of hidden objects
	 * @return array{report: array<string, mixed>, paths: list<string>, references: list<string>} an empty Deck source
	 */
	private function emptyReport(string $status, string $reason): array {
		return ['report' => $this->closed($status, $reason), 'paths' => [], 'references' => []];
	}
}
