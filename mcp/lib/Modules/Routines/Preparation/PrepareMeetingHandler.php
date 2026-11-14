<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesMessages;
use OCA\Mcp\Platform\Meetings\PreparationSources;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;

/**
 * The read-only dossier of one event the actor can already see.
 *
 * Calendar is required. Mail, Deck and files are each skipped, and named, when their grant or their port is missing.
 * Nothing here writes, saves the dossier, or reads a mailbox that is not the actor's.
 */
final class PrepareMeetingHandler {
	/**
	 * @param PreparationSources $sources the read ports
	 * @param GrantPolicy $grants the actor's grants
	 * @param ParticipantRoster $roster addresses already on the occurrence
	 * @param MailCandidates $mail messages tied to those addresses
	 * @param CardCandidates $cards cards named on the occurrence
	 * @param FileCandidates $files paths already in hand
	 */
	public function __construct(
		private PreparationSources $sources,
		private GrantPolicy $grants,
		private ParticipantRoster $roster,
		private MailCandidates $mail,
		private CardCandidates $cards,
		private FileCandidates $files,
	) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments
	 * @return array<string, mixed> the dossier, one JSON block
	 * @throws \InvalidArgumentException when calendar and uid are not given together
	 * @throws ToolFailure when the calendar cannot be read or no visible event matches
	 */
	public function handle(string $userId, array $arguments): array {
		$this->assertPair($arguments);
		if (!$this->grants->granted($userId, 'calendar', 'read')) {
			throw new ToolFailure(WorkflowMessages::notAllowed('calendar_list_events'));
		}
		$query = ['from' => (string)$arguments['from'], 'to' => (string)$arguments['to']];
		if (array_key_exists('calendar', $arguments)) {
			$query['calendar'] = (string)$arguments['calendar'];
		}
		$result = $this->sources->events()->events($userId, $query);
		if (($result['isError'] ?? false) === true) {
			throw new ToolFailure(RoutinesMessages::calendarUnreadable());
		}
		$event = EventChoice::pick(OccurrenceItems::fromResult($result), $arguments);
		if ($event === null) {
			throw new ToolFailure(isset($arguments['uid']) ? RoutinesMessages::eventNotVisible() : RoutinesMessages::noVisibleEvent());
		}
		$people = $this->roster->fromEvent($event, $userId);
		$deck = $this->cards->collect($userId, $event, $people['participants']);
		$dossier = [
			'untrustedDossier' => true,
			'notice' => RoutinesMessages::untrustedDossier(),
			'written' => false,
			'selection' => isset($arguments['uid']) ? 'explicit' : 'next',
			'event' => $this->project($event),
			'participants' => $people['participants'],
			'participantsTruncated' => $people['truncated'],
			'sources' => [
				'mail' => $this->mail->collect($userId, $event, $people['participants']),
				'deck' => $deck['report'],
				'files' => $this->files->collect($userId, $event, $deck['paths'], $deck['references']),
			],
		];
		$cut = OccurrenceItems::cut($result);
		if ($cut !== null) {
			$dossier['window'] = $cut;
			if ($cut['truncated'] && !isset($arguments['uid'])) {
				$dossier['windowNotice'] = RoutinesMessages::windowCut();
			}
		}
		return ToolResult::json($dossier);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @return void
	 * @throws \InvalidArgumentException when only one of calendar and uid is set
	 */
	private function assertPair(array $arguments): void {
		if (array_key_exists('calendar', $arguments) !== array_key_exists('uid', $arguments)) {
			throw new \InvalidArgumentException(RoutinesMessages::calendarAndUid());
		}
	}

	/**
	 * @param array<string, mixed> $event the chosen occurrence
	 * @return array<string, mixed> the fields the dossier may show
	 */
	private function project(array $event): array {
		$row = [];
		foreach (['uid', 'summary', 'start', 'end', 'location', 'calendar', 'allDay'] as $key) {
			if (array_key_exists($key, $event)) {
				$row[$key] = $event[$key];
			}
		}
		return $row;
	}
}
