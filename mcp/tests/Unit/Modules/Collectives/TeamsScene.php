<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Collectives\Db\Collective;
use OCA\Collectives\Model\PageInfo;
use OCA\Collectives\Service\CircleExistsException;
use OCA\Collectives\Service\NotFoundException;
use OCA\Collectives\Service\NotPermittedException;
use OCA\Collectives\Service\UnprocessableEntityException;
use OCP\Files\Folder;

/**
 * The Teams side of the Collectives scene: the teams, the one shared Teams session, and the creation of a collective
 * as `CollectiveService::createCollective` does it in the pinned releases (`:169-247`): a privileged check of the name,
 * a team in the actor's session closed after a `try/catch` without `finally`, the privileged "managed by Collectives"
 * flag, the adoption of a team the actor administers, then the collective, its folder, the landing page and its record.
 * Switches make it fail after any step, or change the world right before it runs.
 */
final class TeamsScene {
	/** Root path of the collectives' storage. */
	public const ROOT = 'appdata_x/collectives';

	/** @var array<string, array{name:string, owner:string, members:list<string>, display?:string}> teams by single id */
	public static array $teams = [];
	/** Account of the open Teams session, null when none. */
	public static ?string $currentUser = null;
	/** Whether a privileged session is open. */
	public static bool $bypass = false;
	/** @var list<string> native calls worth asserting */
	public static array $calls = [];
	/** Whether resolving an identity fails. */
	public static bool $failResolve = false;
	/** Step the creation fails at: null, team-session, flag, collective, folder or page; `no-record` leaves the page without record. */
	public static ?string $failAt = null;
	/** @var (\Closure(): void)|null runs when the native creation starts, before it checks the name */
	public static ?\Closure $beforeCreate = null;
	/** Whether the collectives root exists. */
	public static bool $root = true;
	/** @var array<int, array{landing:int, registered:bool}> what each created collective has on disk */
	public static array $folders = [];
	/** @var list<string> native reads (collectives, find) that fail inside the Teams session they open, as CircleHelper does */
	public static array $failReads = [];
	/** Whether a group resolution set the Teams app as the current app of the request. */
	public static bool $currentApp = false;
	/** Whether resolving a group fails after it set the current app. */
	public static bool $failResolveGroup = false;
	/** @var array<string, bool> accounts whose Teams identity is recorded, besides the actor */
	public static array $identities = [];
	/** Where a member write fails: null, `before` (refused in verify, nothing stored) or `after` (stored, then throws). */
	public static ?string $failMember = null;
	/** @var array<string, list<array{id:string, userId:string, type:int, level:int, status:string}>> memberships by team */
	public static array $entries = [];

	/** @return void three teams: Wiki (alice), Design (bob, with alice) and Secret (carol only, which alice cannot see) */
	public static function reset(): void {
		require_once __DIR__ . '/NativeTeams.php';
		self::$teams = [
			'circle-secret' => ['name' => 'Wiki', 'owner' => 'alice', 'members' => ['alice']],
			'team-design' => ['name' => 'Design Team', 'owner' => 'bob', 'members' => ['bob', 'alice']],
			'team-hidden' => ['name' => 'Secret', 'owner' => 'carol', 'members' => ['carol']],
		];
		self::$currentUser = null;
		self::$bypass = false;
		self::$calls = [];
		self::$failResolve = false;
		self::$failAt = null;
		self::$beforeCreate = null;
		self::$root = true;
		self::$folders = [];
		self::$failReads = [];
		self::$currentApp = false;
		self::$failResolveGroup = false;
		self::$identities = ['bob' => true];
		self::$failMember = null;
		self::$entries = ['circle-secret' => [
			['id' => 'm-alice', 'userId' => 'alice', 'type' => 1, 'level' => 9, 'status' => 'Member'],
			['id' => 'm-bob', 'userId' => 'bob', 'type' => 1, 'level' => 8, 'status' => 'Member'],
			['id' => 'm-carol', 'userId' => 'carol', 'type' => 1, 'level' => 1, 'status' => 'Member'],
			['id' => 'm-staff', 'userId' => 'staff', 'type' => 2, 'level' => 1, 'status' => 'Member'],
			['id' => 'm-dave', 'userId' => 'dave', 'type' => 1, 'level' => 0, 'status' => 'Invited'],
			['id' => 'm-erin', 'userId' => 'erin', 'type' => 1, 'level' => 0, 'status' => 'Requesting'],
			['id' => 'm-frank', 'userId' => 'frank', 'type' => 1, 'level' => 0, 'status' => 'Blocked'],
		]];
	}

	/** @param string $teamId a team @return list<object> its memberships, as Teams lists them */
	public static function members(string $teamId): array {
		if (!isset(self::$entries[$teamId])) {
			return array_map(static fn (string $uid): object => new TeamMember($uid, 1, $uid === self::$teams[$teamId]['owner'] ? 9 : 1),
				self::$teams[$teamId]['members']);
		}
		return array_map(static fn (array $e): object => new TeamMember($e['userId'], $e['type'], $e['level'], $e['status'], $e['id']), self::$entries[$teamId]);
	}

	/** @return int the session user's level in that team, as Teams checks the initiator */
	private static function initiatorLevel(string $teamId): int {
		foreach (self::$entries[$teamId] ?? [] as $e) {
			if ($e['type'] === 1 && $e['userId'] === self::session()) {
				return $e['level'];
			}
		}
		return 0;
	}

	/**
	 * `SingleMemberAdd`: the initiator must be a moderator; an existing member or invitation is refused, a request is
	 * accepted, and a new member is an invitation in a team that requires them (35 `:264-298`).
	 * @return object the membership
	 */
	public static function add(string $teamId, object $target): object {
		self::$calls[] = 'addMember:' . $target->userId;
		if (self::initiatorLevel($teamId) < 4 || self::$failMember === 'before') {
			throw new \RuntimeException('FederatedItemBadRequestException 132');
		}
		foreach (self::$entries[$teamId] as &$e) {
			if ($e['userId'] === $target->userId && $e['type'] === $target->type) {
				if ($e['status'] !== 'Requesting') {
					throw new \RuntimeException('FederatedItemBadRequestException 122');
				}
				$e['level'] = 1;
				$e['status'] = 'Member';
				return new TeamMember($e['userId'], $e['type'], 1, 'Member', $e['id']);
			}
		}
		unset($e);
		$invite = (self::$teams[$teamId]['config'] ?? 0) & 32;
		$entry = ['id' => 'm-' . $target->userId, 'userId' => $target->userId, 'type' => $target->type, 'level' => $invite ? 0 : 1, 'status' => $invite ? 'Invited' : 'Member'];
		self::$entries[$teamId][] = $entry;
		if (self::$failMember === 'after') {
			throw new \RuntimeException('memberPreparing failed');
		}
		return new TeamMember($entry['userId'], $entry['type'], $entry['level'], $entry['status'], $entry['id']);
	}

	/** `MemberLevel`: never the owner, never a pending member (35 `:136-158`). @return object the member */
	public static function level(string $memberId, int $level): object {
		self::$calls[] = 'levelMember:' . $memberId . ':' . $level;
		foreach (self::$entries as $teamId => &$list) {
			foreach ($list as &$e) {
				if ($e['id'] === $memberId) {
					if ($e['level'] === 9 || $e['status'] !== 'Member' || self::initiatorLevel($teamId) < 4 || self::$failMember === 'before') {
						throw new \RuntimeException('FederatedItemBadRequestException');
					}
					$e['level'] = $level;
					return new TeamMember($e['userId'], $e['type'], $level, 'Member', $memberId);
				}
			}
		}
		throw new \RuntimeException('MemberNotFoundException');
	}

	/** `MemberRemove`: never the owner (35 `:53-66`). */
	public static function remove(string $memberId): void {
		self::$calls[] = 'removeMember:' . $memberId;
		foreach (self::$entries as $teamId => $list) {
			foreach ($list as $i => $e) {
				if ($e['id'] === $memberId) {
					if ($e['level'] === 9 || self::initiatorLevel($teamId) <= $e['level'] || self::$failMember === 'before') {
						throw new \RuntimeException('FederatedItemBadRequestException');
					}
					array_splice(self::$entries[$teamId], $i, 1);
					return;
				}
			}
		}
		throw new \RuntimeException('MemberNotFoundException');
	}

	/**
	 * A native Collectives read that opens a Teams session for the actor, as `CircleHelper::getCircles`/`getCircle` do
	 * (`:103-120`): when it is set to fail, the query throws after `startSession` and before `stopSession`.
	 * @param string $uid actor
	 * @param string $read which read this is
	 * @return void
	 */
	public static function nativeRead(string $uid, string $read): void {
		self::$currentUser = $uid;
		if (in_array($read, self::$failReads, true)) {
			self::$calls[] = 'failed:' . $read;
			throw new \RuntimeException('Teams query failed inside the helper session');
		}
		self::$currentUser = null;
	}

	/** @return string the account of the open session; listing without one fails, as upstream needs an initiator */
	public static function session(): string {
		return self::$currentUser ?? throw new \RuntimeException('InitiatorNotFoundException');
	}

	/** @param string $uid account @return list<string> ids of the teams that account is a member of */
	public static function visibleTo(string $uid): array {
		return array_keys(array_filter(self::$teams, static fn (array $team): bool => in_array($uid, $team['members'], true)));
	}

	/**
	 * @param string $uid actor
	 * @param string $lang language the landing page is written in
	 * @param string $name name as given
	 * @param string|null $emoji emoji
	 * @return array{0: Collective, 1: string} the collective and the adoption notice, empty for a new team
	 */
	public static function createCollective(string $uid, string $lang, string $name, ?string $emoji): array {
		self::$calls[] = 'createCollective:' . $lang;
		self::$beforeCreate === null || (self::$beforeCreate)();
		$safe = trim(str_replace(['*', '|', '\\', ':', '"', '<', '>', '?'], '', str_replace('/', '-', $name)), ' .') ?: 'New File';
		self::$bypass = true;
		$existing = array_keys(array_filter(self::$teams, static fn (array $team): bool => strcasecmp($team['name'], $safe) === 0));
		self::$bypass = false;
		$message = '';
		if ($existing !== []) {
			$team = $existing[0];
			if (self::$teams[$team]['owner'] !== $uid) {
				throw new CircleExistsException('A team with that name exists');
			}
			self::$calls[] = 'flag:' . $team;
			$message = 'Coletivo "' . $safe . '" criado para o time existente.';
			foreach (CollectivesScene::$collectives as $data) {
				if (($data['circleId'] ?? null) === $team) {
					throw new UnprocessableEntityException('Collective already exists.');
				}
			}
		} else {
			self::$currentUser = $uid;
			if (self::$failAt === 'team-session') {
				throw new NotPermittedException('Circle name is too short');
			}
			$team = 'team-' . (count(self::$teams) + 1);
			self::$teams[$team] = ['name' => trim((string)preg_replace('/\s+/', ' ', $safe)), 'owner' => $uid, 'members' => [$uid]];
			self::$currentUser = null;
			self::$bypass = true;
			self::$calls[] = 'flag:' . $team;
			if (self::$failAt === 'flag') {
				throw new NotPermittedException('flag failed');
			}
			self::$bypass = false;
		}
		if (self::$failAt === 'collective') {
			throw new \RuntimeException('insert failed');
		}
		$id = max(array_keys(CollectivesScene::$collectives)) + 1;
		CollectivesScene::$collectives[$id] = ['name' => self::$teams[$team]['name'], 'members' => self::$teams[$team]['members'], 'canEdit' => true, 'circleId' => $team];
		if (self::$failAt === 'folder') {
			throw new NotPermittedException('folder failed');
		}
		self::$root = true;
		$landing = CollectivesScene::$tree->addFile('/' . self::ROOT . '/' . $id . '/Readme.md', '# Bem-vindo', 'text/markdown');
		self::$folders[$id] = ['landing' => $landing, 'registered' => !in_array(self::$failAt, ['no-record', 'page'], true)];
		if (self::$failAt === 'page') {
			throw new NotFoundException('Unable to get landing page for collective');
		}
		return [CollectivesScene::collective($id, $uid), $message];
	}

	/** @param string $path absolute path @return bool whether it exists in the collectives' storage */
	public static function exists(string $path): bool {
		$relative = ltrim(substr($path, strlen('/' . self::ROOT)), '/');
		if (!str_starts_with($path, '/' . self::ROOT) || !self::$root) {
			return false;
		}
		$parts = $relative === '' ? [] : explode('/', $relative);
		$folder = self::$folders[(int)($parts[0] ?? 0)] ?? null;
		return match (count($parts)) {
			0 => true,
			1 => $folder !== null,
			2 => $folder !== null && $parts[1] === 'Readme.md',
			default => false,
		};
	}

	/** @param int $id collective @return Folder its folder in the scene's tree */
	public static function folder(int $id): Folder {
		return CollectivesScene::$tree->node('/' . self::ROOT . '/' . $id);
	}

	/**
	 * @param int $id file id asked for
	 * @return PageInfo|null the landing page of a created collective, with a last user only when it has a record
	 */
	public static function landing(int $id): ?PageInfo {
		foreach (self::$folders as $folder) {
			if ($folder['landing'] === $id) {
				return new PageInfo(['id' => $id, 'title' => 'Landing page', 'parentId' => 0, 'fileName' => 'Readme.md',
					'lastUserId' => $folder['registered'] ? 'alice' : null]);
			}
		}
		return null;
	}
}
