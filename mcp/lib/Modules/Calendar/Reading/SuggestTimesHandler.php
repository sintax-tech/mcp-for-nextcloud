<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarFailures;

/** A single bounded page of suggestions; no persisted cursor, recursive search or automatic retry. */
final class SuggestTimesHandler {
	/**
	 * @param AvailabilityQuery $availability FB-1's guarded read
	 * @param SuggestionInput $input search validation
	 * @param AvailabilityCandidates $candidates DST-safe candidate grid
	 */
	public function __construct(private AvailabilityQuery $availability, private SuggestionInput $input, private AvailabilityCandidates $candidates) {}

	/**
	 * @param string $userId authenticated actor
	 * @param array<string, mixed> $arguments explicit search parameters and optional plain next_index
	 * @return array<string, mixed> at most five suggestions and an explicit stateless continuation of at most 32 checks
	 */
	public function handle(string $userId, array $arguments): array {
		return CalendarFailures::run(function () use ($userId, $arguments): array {
			$this->availability->assertUnrestricted($userId);
			$search = $this->input->parse($arguments);
			$audience = $this->availability->audience($userId, $arguments['participants'] ?? null);
			$candidates = $this->candidates->build($search);
			$index = $search['index'];
			if ($index > count($candidates)) throw new CalendarArgumentException(Translator::t('Provide a valid next candidate index.'));
			$suggestions = [];
			$examined = $unknown = 0;
			while ($index < count($candidates) && $examined < 32 && count($suggestions) < 5) {
				$window = $candidates[$index++];
				$rows = $this->availability->check($audience, $window['start'], $window['end']);
				$statuses = array_column($rows, 'status');
				$examined++;
				if (in_array('unknown', $statuses, true)) $unknown++;
				if ($statuses !== [] && count(array_filter($statuses, static fn ($status) => $status === 'free')) === count($statuses)) {
					$suggestions[] = ['start' => $window['start']->format(DATE_ATOM), 'end' => $window['end']->format(DATE_ATOM)];
				}
			}
			$truncated = $index < count($candidates);
			return ToolResult::json([
				'timezone' => $search['zone']->getName(), 'actor_included' => in_array(strtolower($audience['organizer']->getEMailAddress()), $audience['people'], true), 'suggestions' => $suggestions,
				'coverage' => ['from_index' => $search['index'], 'to_index' => $index, 'total_candidates' => count($candidates), 'examined' => $examined, 'unknown_windows' => $unknown],
				'partial' => $search['index'] > 0 || $truncated || $unknown > 0, 'truncated' => $truncated,
				'next_index' => $truncated ? $index : null,
				'continuation' => $truncated ? array_replace($arguments, ['next_index' => $index]) : null,
			]);
		});
	}
}
