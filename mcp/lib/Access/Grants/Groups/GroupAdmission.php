<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCA\Mcp\Access\Policy\SnapshotInvalidated;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Platform\Numbers\DecimalCounter;

/** Checks a group admission and the lifecycle a preview or save is allowed to treat as unchanged. */
final class GroupAdmission {
	/** Whether every credential of a holder is already cut; a generation that cannot be compared is not. */
	public static function allCut(string $highest, string $revokedBelow): bool {
		try {
			return DecimalCounter::compare(DecimalCounter::validate($highest, DecimalCounter::MAX), $revokedBelow) <= 0;
		} catch (\InvalidArgumentException) {
			return false;
		}
	}

	/**
	 * Checks what the external phase answered: cuts only among the holders it was given, and the lifecycles it read.
	 *
	 * @param list<string> $holders the holders that may be cut
	 * @return array{trusted:bool, members:list<string>, cuts:list<string>, sources:list<array{groupId:string, state:?string, gen:?string}>}
	 */
	public static function admission(mixed $admission, array $holders): array {
		if (!is_array($admission) || !is_bool($admission['trusted'] ?? null)
			|| !is_array($admission['members'] ?? null) || !array_is_list($admission['members'])
			|| !is_array($admission['cuts'] ?? null) || !array_is_list($admission['cuts'])) {
			throw new \LogicException('Invalid group admission');
		}
		foreach ($admission['members'] as $uid) {
			if (!is_string($uid)) {
				throw new \LogicException('Invalid group admission');
			}
		}
		$cuts = array_values(array_unique($admission['cuts']));
		foreach ($cuts as $uid) {
			if (!in_array($uid, $holders, true)) {
				throw new \LogicException('A group save may cut only the holders it was given');
			}
		}
		$sources = $admission['sources'] ?? [];
		if (!is_array($sources) || !array_is_list($sources)) {
			throw new \LogicException('Invalid group admission');
		}
		$distinct = [];
		foreach ($sources as $source) {
			$valid = is_array($source) && is_string($source['groupId'] ?? null) && array_key_exists('state', $source) && array_key_exists('gen', $source)
				&& ($source['state'] === null ? $source['gen'] === null
					: in_array($source['state'], GroupLifeMapper::STATES, true) && is_string($source['gen']) && preg_match('/^[0-9a-f]{32}$/D', $source['gen']) === 1);
			if (!$valid) {
				throw new \LogicException('Invalid group admission');
			}
			$source = ['groupId' => $source['groupId'], 'state' => $source['state'], 'gen' => $source['gen']];
			$key = \OCA\Mcp\Db\IdentityKey::group($source['groupId']);
			if (isset($distinct[$key]) && $distinct[$key] !== $source) {
				// Two reads of one group disagree: it moved during the external phase.
				throw new SnapshotInvalidated();
			}
			$distinct[$key] = $source;
		}
		return ['trusted' => $admission['trusted'], 'members' => array_values(array_unique($admission['members'])), 'cuts' => $cuts,
			'sources' => array_values($distinct)];
	}

	/** @return array{state:string, gen:string}|null the part of a lifecycle a preview fixes */
	public static function life(?array $row): ?array {
		return $row === null ? null : ['state' => $row['state'], 'gen' => $row['gen']];
	}

	/**
	 * Whether a lifecycle is the one a group command read, at the same revision (RF3 of the re-reading of F1): the
	 * same state and gen, or both active. The callers compare only at an unchanged revision (the preview's revision is
	 * the save's expected one; run() checks it between the phases), and at an unchanged revision the only change that
	 * keeps a group active with another gen is the renewal of holdLifecycles(), by an issuance or a reset the group
	 * admitted. That renewal changes neither the policy nor the members, so it never invalidates a preview or a save;
	 * every real transition (pending_delete, needs_confirm, purge, recreation) still does.
	 *
	 * @param array{state:string, gen:string}|null $a
	 * @param array{state:string, gen:string}|null $b
	 */
	public static function sameLife(?array $a, ?array $b): bool {
		if ($a === null || $b === null) {
			return $a === $b;
		}
		return $a['state'] === $b['state'] && ($a['gen'] === $b['gen'] || $a['state'] === GroupLifeMapper::ACTIVE);
	}
}
