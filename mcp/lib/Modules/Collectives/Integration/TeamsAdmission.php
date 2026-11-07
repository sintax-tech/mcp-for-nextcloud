<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCA\Mcp\Access\Guests\GuestIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Presentation\FoundingMessages;
use OCP\IConfig;
use OCP\IUserManager;

/**
 * What a call that reaches Teams (Circles) proves before any Collectives or Teams service runs, on every stage: the
 * admission of every Collectives call ({@see CollectivesAdmission}) and the actor's Teams identity already recorded.
 * Resolving an actor without one makes Teams create it and store `circles/userSingleId` (`FederatedUserService.php`
 * 33/34 `:509-531`, 35 `:441-463`); with it, the cached branch only reads. The check is a read of that setting, so a
 * plan never writes; an account that never used Collectives or Teams is asked to open them once. Creating a collective
 * is also refused to guest accounts, as Collectives 4.7.1 refuses them (`CollectiveController.php:91-92`).
 */
class TeamsAdmission {
	/** Grant operation of creating a collective. */
	public const CREATE_COLLECTIVE = 'collective.create';

	/**
	 * @param CollectivesAdmission $admission the admission of every Collectives call
	 * @param IConfig $config the actor's Teams identity setting, read only
	 * @param IUserManager $users the actor's account
	 * @param GuestIdentity $guests whether the actor is a guest account
	 */
	public function __construct(
		private CollectivesAdmission $admission,
		private IConfig $config,
		private IUserManager $users,
		private GuestIdentity $guests,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant operation of the call
	 * @throws ToolFailure when the call is not admitted; nothing native has run
	 */
	public function admit(string $uid, string $operation): void {
		$this->admission->admit($uid, $operation);
		$user = $this->users->get($uid) ?? throw new ToolFailure(CollectivesMessages::unavailable());
		if ($operation === self::CREATE_COLLECTIVE && $this->guests->isGuestAccount($user)) {
			throw new ToolFailure(FoundingMessages::guestsCannotCreate());
		}
		if ($this->config->getUserValue($uid, 'circles', 'userSingleId', '') === '') {
			throw new ToolFailure(FoundingMessages::teamsNotReady());
		}
	}
}
