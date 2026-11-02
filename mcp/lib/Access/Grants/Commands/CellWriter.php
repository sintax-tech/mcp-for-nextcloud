<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use Closure;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Credentials\LocalCredentialEffects;
use OCA\Mcp\Access\Policy\GrantUnknown;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyExecution;
use OCP\IAppConfig;
use OCP\IConfig;

/** Shared raw cell writes and their local credential effects. */
final class CellWriter {
	/** Inheritance states read before removing an individual Allow. */
	private const INHERITED_ELIGIBLE = 'eligible';
	private const INHERITED_LOST = 'lost';
	private const INHERITED_UNKNOWN = 'unknown';

	/**
	 * Composes this participant with the existing official services.
	 *
	 * @param PolicyExecution $execution Existing execution over the single transaction.
	 * @param UserAdmission $users Official account admission.
	 * @param IConfig $config Official raw preference reads and writes.
	 * @param IAppConfig $appConfig Official app value presence.
	 * @param LocalCredentialEffects $effects Local cuts over the shared active attempt.
	 */
	public function __construct(
		private PolicyExecution $execution,
		private UserAdmission $users,
		private IConfig $config,
		private IAppConfig $appConfig,
		private LocalCredentialEffects $effects,
	) {}

	/**
	 * Runs one raw cell change with external user admission before the mutex.
	 *
	 * @param string|null $uid User id, or null for the global switch.
	 * @param string $key Official policy key.
	 * @param string|null $value Raw value, or null to remove the key.
	 * @throws \InvalidArgumentException For an unknown account.
	 * @throws PolicyUnavailable On storage failure or an abandoned context.
	 */
	public function change(?string $uid, string $key, ?string $value): void {
		$uids = $uid === null ? [] : [$uid];
		$this->execution->runInternal($uids, function () use ($uid): void {
			if ($uid !== null) {
				$this->users->assertUser($uid);
			}
		}, fn (): bool => $this->writeCell($uid, $key, $value) !== null);
	}

	/**
	 * The external phase of a command that may remove an individual Allow of eligibility: what each user of such a cell
	 * inherits without it. Only cells that set eligibility to absent are read; the raw value before is read under the
	 * mutex, so a cell that turns out not to remove an Allow ignores its entry. An unknown inheritance is kept, not
	 * thrown: it refuses the command only if the cell really removes an Allow.
	 *
	 * @param list<array{uid:string, key:string, value:?string}> $cells
	 * @param (Closure(string): (list<array{groupId:string, gen:string}>|null))|null $inheritance
	 * @return array<string, array{state:string, lifecycles?:list<array{groupId:string, gen:string}>}> by a digest
	 *     of the uid
	 */
	public function inheritances(array $cells, ?Closure $inheritance): array {
		$inherited = [];
		if ($inheritance === null) {
			return $inherited;
		}
		foreach ($cells as $cell) {
			if ($cell['key'] !== GrantPolicy::ELIGIBLE_KEY || $cell['value'] !== null) {
				continue;
			}
			try {
				$lifecycles = $inheritance($cell['uid']);
				$inherited[hash('sha256', $cell['uid'])] = $lifecycles === null || $lifecycles === []
					? ['state' => self::INHERITED_LOST]
					: ['state' => self::INHERITED_ELIGIBLE, 'lifecycles' => $lifecycles];
			} catch (PolicyUnavailable) {
				$inherited[hash('sha256', $cell['uid'])] = ['state' => self::INHERITED_UNKNOWN];
			}
		}
		return $inherited;
	}

	/**
	 * Writes user cells under the mutex, reading each key's current raw values for all its users in one batch.
	 *
	 * @param list<array{uid:string, key:string, value:?string}> $cells validated, without duplicates
	 * @param array<string, array{state:string, lifecycles?:list<array{groupId:string, gen:string}>}> $inherited
	 *     inheritances(), by a digest of the uid
	 * @return list<array{uid:string, key:string, before:?string, after:?string}> the cells that changed, in order
	 */
	public function applyLocal(array $cells, array $inherited = []): array {
		$byKey = [];
		foreach ($cells as $cell) {
			$byKey[$cell['key']][] = $cell['uid'];
		}
		$current = [];
		foreach ($byKey as $key => $uids) {
			$current[$key] = $this->config->getUserValueForUsers(GrantPolicy::APP, (string)$key, $uids);
		}
		$changes = [];
		foreach ($cells as $cell) {
			$values = $current[$cell['key']];
			$before = array_key_exists($cell['uid'], $values) ? (string)$values[$cell['uid']] : null;
			$change = $this->writeCell($cell['uid'], $cell['key'], $cell['value'], $before,
				$cell['key'] === GrantPolicy::ELIGIBLE_KEY ? ($inherited[hash('sha256', $cell['uid'])] ?? null) : null);
			if ($change !== null) {
				$changes[] = $change;
			}
		}
		return $changes;
	}

	/**
	 * Writes one raw value (null removes the key) when it differs from the stored one, and applies the legacy
	 * revocation scope: switching the service off revokes everyone (global epoch), and a user left without explicit
	 * eligibility, or disconnecting, is signed out (cut), including on an explicit repeated disable of either.
	 *
	 * Removing an individual Allow of eligibility (a reset to inherit, or an undo that restores absence) cuts only when
	 * the user inherits no eligibility (contracts §3.1: a change that keeps eligibility through another source does
	 * not cut; §4.1: the reset recalculates inheritance). With $inherited from the external phase: eligible through
	 * groups keeps the credentials and renews those groups' gens under this mutex (holdLifecycles(), so a deletion
	 * of one of them sees the user without an individual value), lost cuts, unknown refuses the whole command without
	 * any write or cut. Without it the removal cuts, as before. An explicit Deny and a disconnect always cut.
	 *
	 * @param string|null $uid User id, or null for the global service switch.
	 * @param string $key Official policy key.
	 * @param string|null $value Raw value, or null for absence.
	 * @param string|false|null $before the raw value already read in this transaction (null = absent), or false to
	 *     read it here
	 * @param array{state:string, lifecycles?:list<array{groupId:string, gen:string}>}|null $inherited what the user
	 *     inherits without an individual value (inheritances()); null when not prepared
	 * @return array{uid:?string, key:string, before:?string, after:?string}|null the change, or null for a no-op
	 * @throws PolicyUnavailable when an individual Allow is removed and the inheritance is unknown
	 */
	public function writeCell(?string $uid, string $key, ?string $value, string|false|null $before = false, ?array $inherited = null): ?array {
		if ($before === false) {
			// Batch reads preserve presence and legacy MIXED values. Never materialize an inherited default.
			if ($uid === null) {
				$before = $this->appConfig->hasKey(GrantPolicy::APP, $key) ? $this->config->getAppValue(GrantPolicy::APP, $key) : null;
			} else {
				$values = $this->config->getUserValueForUsers(GrantPolicy::APP, $key, [$uid]);
				$before = array_key_exists($uid, $values) ? (string)$values[$uid] : null;
			}
		}
		$changed = $before !== $value;
		$reset = $changed && $uid !== null && $key === GrantPolicy::ELIGIBLE_KEY && $before === '1' && $value === null && $inherited !== null;
		if ($reset && $inherited['state'] === self::INHERITED_UNKNOWN) {
			// Before any write: the command rolls back whole, nothing is cut (503).
			throw new GrantUnknown('Inherited eligibility is unknown');
		}
		if ($reset && $inherited['state'] === self::INHERITED_ELIGIBLE) {
			// Before the write too: a group that left active since the external phase replays the whole attempt.
			$this->effects->holdLifecycles($inherited['lifecycles'] ?? []);
		}
		if ($changed) {
			if ($uid === null) {
				$this->config->setAppValue(GrantPolicy::APP, $key, (string)$value);
			} elseif ($value === null) {
				$this->config->deleteUserValue($uid, GrantPolicy::APP, $key);
			} else {
				$this->config->setUserValue($uid, GrantPolicy::APP, $key, $value);
			}
		}
		if ($key === GrantPolicy::SERVICE_KEY && $value === '0') {
			// Every explicit disable moves the epoch, as 1.0.8 deleted everything on every disable: an off value
			// written outside the app must not leave credentials that come back when the service is switched on.
			$this->effects->revokeAllLocal();
		} elseif ($reset) {
			if ($inherited['state'] === self::INHERITED_LOST) {
				$this->effects->revokeUserLocal($uid);
			}
		} elseif ($uid !== null && (($key === GrantPolicy::ELIGIBLE_KEY && ($value === '0' || ($changed && $value !== '1')))
			|| ($key === GrantPolicy::CONNECTED_KEY && $value === '0'))) {
			$this->effects->revokeUserLocal($uid);
		}
		return $changed ? ['uid' => $uid, 'key' => $key, 'before' => $before, 'after' => $value] : null;
	}
}
