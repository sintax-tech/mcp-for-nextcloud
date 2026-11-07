<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * The synthetic policy snapshot of validation T12 and the probe that reads it, shared by ci/legacy-policy.php (run
 * with the released baseline's code, before and after the upgrade) and Compat/UpgradeSnapshotTest (run with the
 * branch). It references only OCP interfaces and calls on the policy only the methods the baseline and the branch
 * both have, so the same reading is taken by either release's GrantPolicy.
 *
 * The plan: nine users, one per (eligible, connected) pair over '1', '0' and absent; user i stores for the j-th
 * operation of the flattened CATALOG the value VALUES[(i + j) % 3], so every operation is '1', '0' and absent three
 * times each, under three different (eligible, connected) pairs. The service switch is read in each of the three
 * states and put back afterwards.
 */
final class SnapshotProbe {
	/** The three stored states of a value; null is an absent key. */
	public const VALUES = ['1', '0', null];
	/** Resource of the access token the baseline issues to each user of the snapshot. */
	public const RESOURCE = 'https://mcp-it.example/index.php/apps/mcp/mcp';
	/** Trusted CIMD client of those tokens; #28 deliberately refuses hosts outside the existing allowlist. */
	public const CLIENT = 'https://claude.ai/mcp-it-snapshot-client.json';
	private const APP = 'mcp';
	private const SERVICE_KEY = 'service_enabled';

	/** @return list<string> the accounts of the snapshot, created by harness.sh fixtures in the upgrade cells */
	public static function uids(): array {
		return array_map(static fn (int $i): string => 'mcp-it-snap-' . $i, range(0, 8));
	}

	/**
	 * @param array<string, list<string>> $catalog catalog of the release that writes the snapshot (historically GrantPolicy::CATALOG)
	 * @return array<string, array<string, string|null>> by uid, the value of each key of the plan; null = absent
	 */
	public static function plan(array $catalog): array {
		$plan = [];
		foreach (self::uids() as $i => $uid) {
			$values = ['eligible' => self::VALUES[intdiv($i, 3)], 'connected' => self::VALUES[$i % 3]];
			$j = 0;
			foreach ($catalog as $module => $operations) {
				foreach ($operations as $operation) {
					$values['grant_' . $module . '_' . $operation] = self::VALUES[($i + $j++) % 3];
				}
			}
			$plan[$uid] = $values;
		}
		return $plan;
	}

	/**
	 * Every answer the policy gives for the users of the snapshot, under each state of the service switch, which is
	 * written raw (IConfig::setAppValue, as 1.0.8 stores it, never through the writer: switching it off through the
	 * branch's writer would advance the global epoch) and put back exactly as it was.
	 *
	 * @param object $policy the baseline's or the branch's GrantPolicy
	 * @param array<string, list<string>> $catalog that policy's CATALOG
	 * @param list<string> $uids users to read
	 * @return array<string, array<string, mixed>> by service state ('1', '0', 'absent')
	 */
	public static function answers(object $policy, array $catalog, IConfig $config, IAppConfig $appConfig, IUserConfig $userConfig, array $uids): array {
		$appConfig->clearCache(false);
		$had = $appConfig->hasKey(self::APP, self::SERVICE_KEY, null);
		$before = $had ? $config->getAppValue(self::APP, self::SERVICE_KEY) : null;
		$out = [];
		try {
			foreach (self::VALUES as $service) {
				if ($service === null) {
					$config->deleteAppValue(self::APP, self::SERVICE_KEY);
				} else {
					$config->setAppValue(self::APP, self::SERVICE_KEY, $service);
				}
				$appConfig->clearCache(false);
				foreach ($uids as $uid) {
					$userConfig->clearCache($uid, false);
				}
				$users = [];
				foreach ($uids as $uid) {
					$read = static function () use ($policy, $catalog, $uid): array {
						$grants = [];
						foreach ($catalog as $module => $operations) {
							foreach ($operations as $operation) {
								$grants[$module][$operation] = $policy->granted($uid, $module, $operation);
							}
						}
						return ['eligible' => $policy->eligible($uid), 'connected' => $policy->connected($uid),
							'canConnect' => $policy->canConnect($uid), 'grants' => $grants];
					};
					// The branch reads one user's operation over one snapshot, as a request does; 1.0.8 has no within().
					$users[$uid] = method_exists($policy, 'within') ? $policy->within($uid, $read) : $read();
				}
				$matrix = [];
				foreach ($policy->forUsers($uids) as $uid => $state) {
					$matrix[(string)$uid] = ['eligible' => $state['eligible'], 'connected' => $state['connected'], 'grants' => $state['grants']];
				}
				ksort($matrix, SORT_STRING);
				$only = static fn (array $found): array => array_values(array_intersect(array_map('strval', $found), $uids));
				$out[$service ?? 'absent'] = ['globalEnabled' => $policy->globalEnabled(), 'users' => $users, 'matrix' => $matrix,
					'eligibleUsers' => $only($policy->flaggedUsers('eligible')), 'connectedFlag' => $only($policy->flaggedUsers('connected')),
					'connectedUsers' => $only($policy->connectedUsers())];
			}
		} finally {
			if ($before === null) {
				$config->deleteAppValue(self::APP, self::SERVICE_KEY);
			} else {
				$config->setAppValue(self::APP, self::SERVICE_KEY, $before);
			}
			$appConfig->clearCache(false);
		}
		return $out;
	}

	/**
	 * The stored preferences as bytes: every key of the app for each user, with its value, type, lazy flag and flags,
	 * and the service switch of the app config (presence, value, type, lazy). Read through the official config
	 * services only, never by SQL on the core tables.
	 *
	 * @param list<string> $uids users to read
	 * @return array{users: array<string, array<string, array{value:string, type:string, lazy:bool, flags:int}>>, service: array{value:string, type:int, lazy:bool}|null}
	 */
	public static function raw(IConfig $config, IAppConfig $appConfig, IUserConfig $userConfig, array $uids): array {
		$appConfig->clearCache(false);
		$users = [];
		foreach ($uids as $uid) {
			$userConfig->clearCache($uid, false);
			$keys = $userConfig->getKeys($uid, self::APP);
			sort($keys, SORT_STRING);
			$users[$uid] = [];
			foreach ($keys as $key) {
				$lazy = $userConfig->isLazy($uid, self::APP, $key);
				$users[$uid][$key] = ['value' => $config->getUserValue($uid, self::APP, $key, "\0absent"),
					'type' => $userConfig->getValueType($uid, self::APP, $key)->name, 'lazy' => $lazy,
					'flags' => $userConfig->getValueFlags($uid, self::APP, $key, $lazy)];
			}
		}
		$service = null;
		if ($appConfig->hasKey(self::APP, self::SERVICE_KEY, null)) {
			$service = ['value' => $config->getAppValue(self::APP, self::SERVICE_KEY, "\0absent"),
				'type' => $appConfig->getValueType(self::APP, self::SERVICE_KEY), 'lazy' => $appConfig->isLazy(self::APP, self::SERVICE_KEY)];
		}
		return ['users' => $users, 'service' => $service];
	}
}
