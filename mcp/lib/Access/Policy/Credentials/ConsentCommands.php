<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use Closure;
use OCA\Mcp\Access\Grants\Commands\{CellWriter, UserAdmission};
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Db\AuthUsersMapper;
use OCP\IConfig;

/** Consent issuance in the single policy transaction. */
final class ConsentCommands implements ConsentCommandsContract {
	/**
	 * Composes this command with the existing policy participants.
	 *
	 * @param CredentialCommands $credentials Credential units over the same execution.
	 * @param UserAdmission $admission External account admission.
	 * @param IConfig $config Official policy configuration.
	 * @param LocalCredentialEffects $effects Lifecycle holds in the shared attempt.
	 * @param CellWriter $cells Connected flag writes on the official connection.
	 * @param AuthUsersMapper $authUsers The same user generation mapper.
	 */
	public function __construct(
		private CredentialCommands $credentials,
		private UserAdmission $admission,
		private IConfig $config,
		private LocalCredentialEffects $effects,
		private CellWriter $cells,
		private AuthUsersMapper $authUsers,
	) {}

	/** {@inheritDoc} */
	public function consent(string $uid, Closure $admit, Closure $insertCode, ?Closure $classify = null): bool {
		$allowed = false;
		$this->credentials->runCredential($uid, function () use ($uid, $admit): ?array {
			$this->admission->assertUser($uid);
			return $admit();
		}, function (?array $lifecycles, array $current) use ($uid, $insertCode, &$allowed): bool {
			$individual = $this->config->getUserValueForUsers(GrantPolicy::APP, GrantPolicy::ELIGIBLE_KEY, [$uid])[$uid] ?? null;
			// An individual Allow admits by itself; a group admits only while the user has no individual value.
			$allowed = $lifecycles !== null
				&& $this->config->getAppValue(GrantPolicy::APP, GrantPolicy::SERVICE_KEY, '0') === '1'
				&& ($individual === '1' || ($individual === null && $lifecycles !== []));
			if (!$allowed) {
				return false;
			}
			$this->effects->holdLifecycles($lifecycles);
			$changed = $this->cells->writeCell($uid, GrantPolicy::CONNECTED_KEY, '1') !== null;
			$insertCode($this->authUsers->issue($uid), $current['auth_epoch']);
			return $changed;
		}, $classify);
		return $allowed;
	}
}
