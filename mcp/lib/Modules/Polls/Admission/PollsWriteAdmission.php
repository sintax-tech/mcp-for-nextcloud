<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Admission;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Modules\Polls\Integration\PollsMutationProbe;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCP\App\IAppManager;
use OCP\IUserManager;

/** Exact independently denied mutation grants, checked again on direct work calls. */
final class PollsWriteAdmission {
	/**
	 * @param GrantPolicy $policy Current policy operation.
	 * @param IAppManager $apps Per-user enablement.
	 * @param IUserManager $users Actor lookup.
	 * @param GuestRestrictions $guests Native guest restrictions.
	 * @param PollsMutationProbe $probe Installation compatibility.
	 * @return void
	 */
	public function __construct(
		private GrantPolicy $policy,
		private IAppManager $apps,
		private IUserManager $users,
		private GuestRestrictions $guests,
		private PollsMutationProbe $probe,
	) {
	}

	/**
	 * @param string $uid Actor.
	 * @param string $operation Exact operation.
	 * @param int|null $pollId Scope target when known.
	 * @return string Installed supported version.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function check(string $uid, string $operation, ?int $pollId = null): string {
		$user = $uid === '' ? null : $this->users->get($uid);
		if (!in_array($operation, ['configure', 'options', 'options.remove', 'share', 'invite'], true)
			|| $user === null
			|| !$this->policy->granted($uid, 'polls', $operation)
			|| !AppEnablement::forUser($this->apps, 'polls', $user)
			|| !$this->guests->allowsApp($uid, 'polls')
			|| ($pollId !== null && !$this->policy->inScope($uid, 'polls', (string) $pollId))
			|| !$this->probe->supports('polls.native_mutation_api')) {
			throw PollsRefusal::unavailable();
		}
		return $this->apps->getAppVersion('polls');
	}
}
