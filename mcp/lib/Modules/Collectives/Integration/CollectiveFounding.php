<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The only door to `CollectiveService::createCollective`, the official path the Collectives app takes for its own
 * "New collective" (`CollectiveController::create`, 4.7.1 `:90-110`): the actor and their language, the name and the
 * optional emoji. Collectives creates the team, the collective, its folder and the landing page; on Nextcloud 35 Teams
 * may add a team folder. It is called once, never retried, and the shared Teams session is closed afterwards whatever
 * it threw ({@see TeamsSession::closingAfter()}).
 *
 * What it reports is only what the call returned. The second element Collectives returns starts empty and is only set
 * when it adopted an existing team (4.0.0 `:187,:197`, later `:180,:190`); its text is translated, so only whether it
 * is empty is used.
 */
class CollectiveFounding {
	/**
	 * @param ContainerInterface $container server container the native services are shared in
	 * @param TeamsSession $teams closes the Teams session after the native call
	 * @param IUserManager $users the actor's account
	 * @param IFactory $languages the actor's language, as the native controller passes it
	 */
	public function __construct(
		private ContainerInterface $container,
		private TeamsSession $teams,
		private IUserManager $users,
		private IFactory $languages,
	) {
	}

	/**
	 * @param string $uid authenticated actor, already admitted
	 * @param string $name the name exactly as the plan showed it
	 * @param string|null $emoji the emoji, already validated, or null
	 * @return array{collective_id:int|null, adopted:bool|null, error:Throwable|null} what the call answered: the id and
	 *     whether Collectives said it adopted a team, or what it threw (then both are null)
	 */
	public function create(string $uid, string $name, ?string $emoji): array {
		try {
			$answer = $this->teams->closingAfter(function () use ($uid, $name, $emoji): array {
				$user = $this->users->get($uid) ?? throw new ToolFailure(CollectivesMessages::unavailable());
				$service = $this->container->get(CollectivesSurface::COLLECTIVES);
				if (!is_a($service, CollectivesSurface::COLLECTIVES)) {
					throw new ToolFailure(CollectivesMessages::unavailable());
				}
				return $service->createCollective($uid, $this->languages->getUserLanguage($user), $name, $emoji);
			});
		} catch (Throwable $e) {
			return ['collective_id' => null, 'adopted' => null, 'error' => $e];
		}
		$collective = $answer[0] ?? null;
		$id = is_object($collective) ? PageProjection::collective($collective)['id'] : null;
		return ['collective_id' => $id, 'adopted' => $id === null ? null : ($answer[1] ?? '') !== '', 'error' => null];
	}
}
