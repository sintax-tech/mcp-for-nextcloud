<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use Psr\Container\ContainerInterface;

/**
 * Strict policy admission before any Circles expansion can start a native super session.
 */
final class CardLinkPolicy {
	/**
	 * Raw core policy inputs bound to the plan, including flags that are currently ineffective.
	 */
	private const CORE_INPUTS = [
		'shareapi_enabled' => 'yes',
		'shareapi_exclude_groups' => 'no',
		'shareapi_exclude_groups_list' => '[]',
		'shareapi_only_share_with_group_members' => 'no',
		'shareapi_only_share_with_group_members_exclude_group_list' => '',
		'shareapi_allow_group_sharing' => 'yes',
		'shareapi_allow_resharing' => 'yes',
		'shareapi_default_internal_expire_date' => 'no',
		'shareapi_enforce_internal_expire_date' => 'no',
		'shareapi_internal_expire_after_n_days' => '7',
	];

	/**
	 * Receive native policy and explicit authenticated-actor services.
	 *
	 * @param IManager $shares Native effective sharing policy getters.
	 * @param IConfig $config Core policy inputs used for plan invalidation.
	 * @param IAppManager $apps Determines whether the optional Guests app is enabled.
	 * @param IUserManager $users Resolves the exact authenticated account.
	 * @param IUserSession $session Must contain that same account before optional service lookup.
	 * @param ContainerInterface $container Injected container for the optional Guests identity service.
	 * @return void
	 */
	public function __construct(private IManager $shares, private IConfig $config, private IAppManager $apps,
		private IUserManager $users, private IUserSession $session, private ContainerInterface $container) {
	}

	/**
	 * Read strict effective policy and actor identity; never infer Guest status from hide_users.
	 *
	 * Failure to identify a user or read an enabled Guests service propagates as a closed refusal.
	 * Run before BoardRecipientResolver: native Circles permission state is not restored by Deck.
	 *
	 * @param string $uid Authenticated actor.
	 * @param bool $reshare Whether the file belongs to another account.
	 * @return array<string, mixed> Internal policy snapshot; no raw settings are exposed in a plan.
	 * @throws \Throwable On a denied, unknown or unreadable policy/actor.
	 */
	public function inspect(string $uid, bool $reshare): array {
		$user = $this->users->get($uid);
		if ($user === null || $user->getUID() !== $uid || $this->session->getUser()?->getUID() !== $uid) {
			throw \OCA\Mcp\Modules\Deck\Attachments\CardFiles::unavailable();
		}
		$guestsEnabled = AppEnablement::forAnyone($this->apps, 'guests');
		if ($guestsEnabled && $this->container->get('OCA\Guests\GuestManager')->isGuest($user)) {
			throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException(\OCA\Mcp\Modules\Deck\Attachments\CardLinkMessages::refused());
		}
		$raw = [];
		foreach (self::CORE_INPUTS as $key => $default) {
			$raw[$key] = $this->config->getAppValue('core', $key, $default);
		}
		$state = ['enabled' => $this->shares->shareApiEnabled(),
			'disabledForActor' => $this->shares->sharingDisabledForUser($uid),
			'groupOnly' => $this->shares->shareWithGroupMembersOnly(),
			'allowGroups' => $this->shares->allowGroupSharing(),
			'internalDefaultExpiry' => $this->shares->shareApiInternalDefaultExpireDate(),
			'internalExpiryEnforced' => $this->shares->shareApiInternalDefaultExpireDateEnforced(),
			'guestsEnabled' => $guestsEnabled, 'raw' => $raw];
		if (!in_array($raw['shareapi_allow_resharing'], ['yes', 'no'], true)
			|| !$state['enabled'] || $state['disabledForActor'] || $state['groupOnly']
			|| $state['internalExpiryEnforced'] || ($reshare && $raw['shareapi_allow_resharing'] !== 'yes')) {
			throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException(\OCA\Mcp\Modules\Deck\Attachments\CardLinkMessages::refused());
		}
		return $state;
	}

	/**
	 * Apply the group-sharing rule to the complete structural audience after resolution.
	 *
	 * This check uses captured scalar policy only and performs no Circles-dependent authorization.
	 *
	 * @param array<string, mixed> $state Policy captured before expansion.
	 * @param RecipientSnapshot $recipients Complete current board recipients and structural flags.
	 * @return void
	 * @throws DeckRefusalException When a group or circle ACL conflicts with the administrator policy.
	 */
	public function assertAudience(array $state, \OCA\Mcp\Modules\Deck\Recipients\RecipientSnapshot $recipients): void {
		if (!$state['allowGroups'] && ($recipients->hasGroupAcl || $recipients->hasCircleAcl)) {
			throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException(\OCA\Mcp\Modules\Deck\Attachments\CardLinkMessages::refused());
		}
	}
}
