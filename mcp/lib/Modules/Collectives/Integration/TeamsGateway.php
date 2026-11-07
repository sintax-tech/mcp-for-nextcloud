<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The only door to the members of a collective's team, through the Teams manager the Collectives app relies on, the
 * same member service its own interface reaches (`LocalController::memberAdd`, `memberLevel`, `memberRemove`). Every
 * call runs in the actor's Teams session ({@see TeamsSession}), so Teams checks the actor's own level; MCP checks it
 * first too. Adding resolves the person or group the way that interface does, and a group seals the session first.
 * Writes are attempted once and what they throw is left to the caller, who reads the team again.
 */
class TeamsGateway {
	/** `Member::TYPE_USER`, checked by {@see TeamsSurface}. */
	public const TYPE_USER = 1;
	/** `Member::TYPE_GROUP`, checked by {@see TeamsSurface}. */
	public const TYPE_GROUP = 2;
	/** `Circle::CFG_SINGLE | Circle::CFG_PERSONAL`: teams whose members cannot change. */
	private const FIXED = 1 | 2;
	/** `Circle::CFG_INVITE`: a new member is an invitation until accepted. */
	private const INVITE = 32;

	/**
	 * @param ContainerInterface $container server container the native services are shared in
	 * @param TeamsSession $session the actor's Teams session
	 */
	public function __construct(
		private ContainerInterface $container,
		private TeamsSession $session,
	) {
	}

	/**
	 * @param int $cid collective id
	 * @param string $uid authenticated actor, already admitted
	 * @return array{id:int, name:string, team_id:string, actor_level:int} the collective, its team and the actor's level in it
	 * @throws ToolFailure when the collective is unknown to the actor
	 */
	public function collective(int $cid, string $uid): array {
		try {
			$service = $this->container->get(CollectivesSurface::COLLECTIVES);
			if (!is_a($service, CollectivesSurface::COLLECTIVES)) {
				throw new ToolFailure(CollectivesMessages::unavailable());
			}
			// Reading the collective opens a Teams session inside Collectives (CircleHelper), closed only after its try/catch.
			$collective = $this->session->closingAfter(static fn (): object => $service->getCollective($cid, $uid));
			$shown = PageProjection::collective($collective);
			return ['id' => $shown['id'], 'name' => $shown['name'], 'team_id' => (string)$collective->getCircleId(),
				'actor_level' => (int)((array)$collective->jsonSerialize())['level']];
		} catch (Throwable $e) {
			throw CollectivesGateway::failure($e);
		}
	}

	/**
	 * @param string $uid authenticated actor
	 * @param string $teamId the collective's team
	 * @param bool $readback whether this read is the readback of a call that sealed the session
	 * @return array{fixed:bool, invite:bool, members:list<array<string, mixed>>} the team's settings and its members now
	 * @throws ToolFailure when the team cannot be read for the actor
	 */
	public function team(string $uid, string $teamId, bool $readback = false): array {
		try {
			return $this->session->asActor($uid, static function (object $teams) use ($teamId): array {
				$team = $teams->getCircle($teamId, null);
				return ['fixed' => $team->isConfig(self::FIXED, 0), 'invite' => $team->isConfig(self::INVITE, 0),
					'members' => array_values(array_map(static fn (object $member): array => MemberProjection::of($member),
						array_filter($team->getMembers(0), static fn (mixed $member): bool => is_object($member))))];
			}, $readback);
		} catch (Throwable $e) {
			throw CollectivesGateway::failure($e);
		}
	}

	/**
	 * One native addition as plain member (or invitation), never retried.
	 * @param string $uid authenticated actor
	 * @param string $teamId the collective's team
	 * @param int $type {@see self::TYPE_USER} or {@see self::TYPE_GROUP}
	 * @param string $id account or group id
	 * @throws Throwable whatever the resolution or the native call throws, untouched
	 */
	public function add(string $uid, string $teamId, int $type, string $id): void {
		$this->session->asActor($uid, function (object $teams) use ($teamId, $type, $id): void {
			if ($type === self::TYPE_GROUP) {
				$this->session->seal();
			}
			$target = $type === self::TYPE_USER ? $teams->getLocalFederatedUser($id) : $teams->getFederatedUser($id, self::TYPE_GROUP);
			$teams->addMember($teamId, $target);
		});
	}

	/**
	 * One native level change, never retried.
	 * @param string $uid authenticated actor
	 * @param string $memberId the member, as Teams identifies it
	 * @param int $level member, moderator or admin level
	 * @throws Throwable whatever the native call throws, untouched
	 */
	public function level(string $uid, string $memberId, int $level): void {
		$this->session->asActor($uid, static fn (object $teams): object => $teams->levelMember($memberId, $level));
	}

	/**
	 * One native removal, never retried.
	 * @param string $uid authenticated actor
	 * @param string $memberId the member, as Teams identifies it
	 * @throws Throwable whatever the native call throws, untouched
	 */
	public function remove(string $uid, string $memberId): void {
		$this->session->asActor($uid, static function (object $teams) use ($memberId): void {
			$teams->removeMember($memberId);
		});
	}
}
