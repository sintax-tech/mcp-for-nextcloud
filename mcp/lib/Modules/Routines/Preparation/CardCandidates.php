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
 * Cards named by id on the occurrence, and the file paths their links already carry.
 *
 * There is no search by title or assignee. A card the reader refuses is omitted, with no count of those refusals.
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
	 * @return array{report: array<string, mixed>, paths: list<string>} the Deck source, and paths for the file source
	 */
	public function collect(string $userId, array $event): array {
		$deck = $this->sources->deck();
		if ($deck === null) {
			return ['report' => $this->closed('unavailable', 'unbound'), 'paths' => []];
		}
		if (!$this->grants->granted($userId, 'deck', 'read')) {
			return ['report' => $this->closed('not_consulted', 'grant'), 'paths' => []];
		}
		$ids = $this->ids($event);
		if ($ids === []) {
			return ['report' => $this->closed('consulted', 'no_card'), 'paths' => []];
		}
		return $this->read($deck, $userId, $ids);
	}

	/**
	 * @param MeetingDeckRead $deck the Deck port
	 * @param string $userId the actor
	 * @param list<int> $ids positive card ids from the occurrence
	 * @return array{report: array<string, mixed>, paths: list<string>} the Deck source and the linked paths
	 */
	private function read(MeetingDeckRead $deck, string $userId, array $ids): array {
		$truncated = count($ids) > DossierBudget::MAX_CARDS;
		$kept = [];
		$paths = [];
		foreach (array_slice($ids, 0, DossierBudget::MAX_CARDS) as $id) {
			try {
				$card = ResultJson::object($deck->card($userId, ['cardId' => $id]));
				$links = ResultJson::object($deck->links($userId, ['cardId' => $id]));
			} catch (ToolFailure | \InvalidArgumentException) {
				continue;
			}
			$kept[] = [
				'source' => 'deck',
				'reason' => 'event_card',
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
		}
		return [
			'report' => ['status' => 'consulted', 'reason' => 'event_card', 'truncated' => $truncated, 'candidates' => $kept],
			'paths' => $paths,
		];
	}

	/**
	 * @param array<string, mixed> $event the chosen occurrence
	 * @return list<int> positive card ids, once each, in the order given
	 */
	private function ids(array $event): array {
		$ids = [];
		foreach ((array)($event['cardIds'] ?? []) as $id) {
			if (is_int($id) && $id > 0 && !in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}
		return $ids;
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
}
