<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Creation;

use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Integration\FoundingReadback;
use OCA\Mcp\Modules\Collectives\Integration\TeamsDirectory;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Presentation\FoundingMessages;
use Throwable;

/**
 * The receipt of a collective creation, from a fresh read only; nothing is repaired, retried or undone.
 *
 * - `applied`: the collective, its folder, its Readme.md and its page record were read back, its team is owned by the
 *   actor alone, Collectives returned no adoption notice and the team was not among those the actor saw before.
 * - `partial`: the collective exists but one of those pieces is missing or unproven, or the team was adopted.
 * - `uncertain`: the native call failed, or the collective could not be read back. After a failure, whether an
 *   existing team was used cannot be told, so `adopted_existing_team` is `unknown`.
 * - `not_applied`: Collectives refused the name of a team the actor cannot see and nothing new was found.
 */
class CollectiveReceipt {
	/**
	 * @param FoundingReadback $readback collectives and their pieces, read now
	 * @param TeamsDirectory $teams the team of the collective and the teams the actor sees, read now
	 */
	public function __construct(
		private FoundingReadback $readback,
		private TeamsDirectory $teams,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param string $name the name the plan showed and the call sent
	 * @param array{collective_ids:list<int>, team_ids:list<string>} $before what the actor saw right before the call
	 * @param array{collective_id:int|null, adopted:bool|null, error:Throwable|null} $answer what the call answered
	 * @return array<string, mixed> the receipt
	 */
	public function of(string $uid, string $name, array $before, array $answer): array {
		$base = ['nativeCallAttempted' => true, 'requested' => ['name' => $name]];
		try {
			$found = $this->readback->collectives($uid);
		} catch (Throwable) {
			return $base + ['status' => 'uncertain', 'adopted_existing_team' => 'unknown', 'message' => CollectivesMessages::uncertain()];
		}
		if ($answer['error'] !== null) {
			return $base + $this->afterFailure($uid, $name, $before, $found, $answer['error']);
		}
		$collective = array_values(array_filter($found, static fn (array $c): bool => $c['id'] === $answer['collective_id']))[0] ?? null;
		if ($collective === null) {
			return $base + ['status' => 'uncertain', 'adopted_existing_team' => 'unknown', 'message' => CollectivesMessages::uncertain()];
		}
		try {
			$team = $this->teams->team($uid, $collective['team_id']);
		} catch (Throwable) {
			$team = null;
		}
		$adopted = $answer['adopted'] === true || in_array($collective['team_id'], $before['team_ids'], true)
			|| ($team !== null && (!$team['owner_is_actor'] || !$team['only_member_is_actor']));
		$pieces = $this->readback->pieces($collective['id'], $uid);
		$complete = $team !== null && $pieces['root'] && $pieces['folder'] && $pieces['landing'] && $pieces['registered'] === true;
		$status = !$adopted && $complete ? 'applied' : 'partial';
		return $base + ['status' => $status, 'adopted_existing_team' => $adopted,
			'collective' => ['id' => $collective['id'], 'name' => $collective['name']],
			'name_suffixed' => $collective['name'] !== $name, 'team_read' => $team !== null, 'pieces' => $pieces,
			'message' => $adopted ? FoundingMessages::adopted() : ($complete ? CollectivesMessages::applied() : FoundingMessages::incomplete())];
	}

	/**
	 * @param string $uid authenticated actor
	 * @param string $name the name sent
	 * @param array{collective_ids:list<int>, team_ids:list<string>} $before what the actor saw right before the call
	 * @param list<array{id:int, name:string, team_id:string}> $found the actor's collectives now
	 * @param Throwable $error what the native call threw
	 * @return array<string, mixed> the receipt of a failed call: what new collective or team of that name exists now
	 */
	private function afterFailure(string $uid, string $name, array $before, array $found, Throwable $error): array {
		$wanted = mb_strtolower($name);
		$collectives = array_values(array_filter($found, static fn (array $c): bool
			=> !in_array($c['id'], $before['collective_ids'], true) && mb_strtolower($c['name']) === $wanted));
		try {
			$teams = array_values(array_filter($this->teams->visibleTeams($uid), static fn (array $t): bool
				=> !in_array($t['id'], $before['team_ids'], true)
				&& in_array($wanted, array_map(static fn (string $n): string => mb_strtolower($n), $t['names']), true)));
		} catch (Throwable) {
			$teams = null;
		}
		$native = CollectivesGateway::failure($error)->getMessage();
		if ($collectives === [] && $teams === [] && str_ends_with($error::class, 'CircleExistsException')) {
			return ['status' => 'not_applied', 'adopted_existing_team' => false, 'native_error' => $native, 'message' => FoundingMessages::nameTaken($name)];
		}
		return ['status' => 'uncertain', 'adopted_existing_team' => 'unknown', 'native_error' => $native,
			'new_collectives' => array_map(static fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], $collectives),
			'new_teams_seen' => $teams === null ? 'unknown' : count($teams),
			'message' => $collectives === [] && $teams === [] ? FoundingMessages::failedNothingSeen() : FoundingMessages::failedHalfway()];
	}
}
