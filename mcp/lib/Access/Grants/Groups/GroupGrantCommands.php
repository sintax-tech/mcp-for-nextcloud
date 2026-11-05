<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use Closure;
use OCA\Mcp\Access\Policy\Credentials\CredentialHolders;
use OCA\Mcp\Access\Policy\Credentials\LocalCredentialEffects;
use OCA\Mcp\Access\Policy\GroupSourceUntrusted;
use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\SnapshotInvalidated;
use OCA\Mcp\Access\Policy\Transactions\PolicyExecution;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Platform\Numbers\DecimalCounter;
use OCP\AppFramework\Utility\ITimeFactory;

/** Previews and saves a group's policy on the shared policy transaction. */
final class GroupGrantCommands implements GroupGrantCommandsContract {
	/**
	 * Composes this participant with the existing execution and group stores.
	 *
	 * @param PolicyExecution $execution Existing execution over the single transaction.
	 * @param ITimeFactory $time Official clock.
	 * @param GroupLifeMapper $life Group lifecycle store.
	 * @param GroupGrantsMapper $groupGrants Group grants store.
	 * @param CredentialHolders $holders Live credential holders.
	 * @param LocalCredentialEffects $effects Local credential cuts.
	 * @param GroupPreview $previews Stored group previews.
	 */
	public function __construct(
		private PolicyExecution $execution,
		private ITimeFactory $time,
		private GroupLifeMapper $life,
		private GroupGrantsMapper $groupGrants,
		private CredentialHolders $holders,
		private LocalCredentialEffects $effects,
		private GroupPreview $previews,
	) {}
	/** {@inheritDoc} */
	public function previewGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, Closure $admit): array {
		return $this->groupCommand($expectedRevision, $actor, $gid, PolicyEventPayload::groupGrants($grants), null, $admit);
	}

	/** {@inheritDoc} */
	public function setGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, string $previewId, Closure $admit): array {
		DecimalCounter::validate($expectedRevision);
		$grants = PolicyEventPayload::groupGrants($grants);
		$preview = $this->previews->preview($previewId, $actor);
		if ($preview['gid'] !== $gid || $preview['grants'] !== $grants) {
			throw new \InvalidArgumentException('The save differs from its preview');
		}
		if ($preview['revision'] !== $expectedRevision) {
			throw new PolicyConflict();
		}
		return $this->groupCommand($expectedRevision, $actor, $gid, $grants, $preview, $admit);
	}

	/**
	 * One group preview ($preview null) or save, as one admin command.
	 *
	 * @param array<string, list<string>> $grants canonical target set
	 * @param array{gid:string, grants:array, memberHash:string, life:?array}|null $preview the save's preview
	 */
	private function groupCommand(string $expected, string $actor, string $gid, array $grants, ?array $preview, Closure $admit): array {
		if (!PolicyEventPayload::validUid($actor) || !PolicyEventPayload::validUid($gid)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		$outcome = [];
		$result = $this->execution->runAdmin($expected, [], function (array $observed) use ($gid, $grants, $preview, $admit): array {
			// Local reads first, H0 included, before anything remote (contracts §2.3).
			$now = $this->time->getTime();
			$life = GroupAdmission::life($this->life->get($gid));
			$current = $this->groupGrants->forGroups([$gid])[0]['grants'];
			$withdraws = PolicyEventPayload::grantsEligibility($current) && !PolicyEventPayload::grantsEligibility($grants);
			$holders = $withdraws ? $this->holders->holders($now, $observed['auth_epoch']) : [];
			$candidates = [];
			foreach ($holders as $holder) {
				if (!GroupAdmission::allCut($holder['gen'], $holder['revoked_below'])) {
					$candidates[] = $holder['uid'];
				}
			}
			$admission = GroupAdmission::admission($admit(['gid' => $gid, 'grants' => $grants, 'current' => $current,
				'withdraws' => $withdraws, 'holders' => $candidates]), $withdraws ? $candidates : []);
			if ($grants !== [] && !$admission['trusted']) {
				throw new GroupSourceUntrusted();
			}
			$memberHash = PolicyEventPayload::memberHash($admission['members']);
			if ($preview !== null && ($preview['memberHash'] !== $memberHash || !GroupAdmission::sameLife($preview['life'], $life))) {
				throw new PolicyConflict();
			}
			return ['now' => $now, 'life' => $life, 'current' => $current, 'withdraws' => $withdraws, 'holders' => $holders,
				'admission' => $admission, 'memberHash' => $memberHash];
		}, function (array $snapshot, array $current, string $commandId, PolicyOutbox $outbox) use ($actor, $gid, $grants, $preview, &$outcome): bool {
			$stored = $this->life->get($gid);
			if (!GroupAdmission::sameLife(GroupAdmission::life($stored), $snapshot['life']) || $this->groupGrants->forGroups([$gid])[0]['grants'] !== $snapshot['current']) {
				throw new SnapshotInvalidated();
			}
			if ($snapshot['withdraws'] && $this->holders->holders($snapshot['now'], $current['auth_epoch']) !== $snapshot['holders']) {
				throw new SnapshotInvalidated();
			}
			foreach ($snapshot['admission']['sources'] as $source) {
				if ($source['groupId'] !== $gid && !GroupAdmission::sameLife(GroupAdmission::life($this->life->get($source['groupId'])), GroupAdmission::life($source['gen'] === null ? null : $source))) {
					throw new SnapshotInvalidated();
				}
			}
			if ($preview === null) {
				$outcome = ['previewId' => $outbox->add(PolicyEventsMapper::KIND_PREVIEW, PolicyEventPayload::preview($actor, $gid,
					$current['revision'], $grants, $snapshot['memberHash'], $snapshot['life'])),
					'revision' => $current['revision'], 'groupId' => $gid, 'grants' => $grants, 'current' => $snapshot['current'],
					'state' => $stored['state'] ?? null, 'members' => count($snapshot['admission']['members']),
					'trusted' => $snapshot['admission']['trusted'], 'withdraws' => $snapshot['withdraws'],
					'revokes' => count($snapshot['admission']['cuts'])];
				return false;
			}
			if ($grants === $snapshot['current'] && ($grants === [] ? $stored === null : $stored['state'] === GroupLifeMapper::ACTIVE)) {
				$outcome = ['changed' => false, 'state' => $stored['state'] ?? null, 'revoked' => 0];
				return false;
			}
			$revision = PolicyExecution::next($current['revision']);
			$state = null;
			// Lifecycle first, conditioned on the gen read above; the rows follow only once it is held.
			if ($grants === []) {
				if ($stored !== null && !$this->life->delete($gid, $stored['gen'])) {
					throw new SnapshotInvalidated();
				}
			} else {
				// policy_rev is the revision this commit writes, never the one before the CAS (ticket 07).
				$gen = bin2hex(random_bytes(16));
				if ($stored === null) {
					// Only the writer creates a lifecycle, and only under the mutex: no pre-event can race the insert.
					$this->life->put($gid, GroupLifeMapper::ACTIVE, $gen, $revision);
				} elseif (!$this->life->replace($gid, $stored['gen'], GroupLifeMapper::ACTIVE, $gen, $revision)) {
					throw new SnapshotInvalidated();
				}
				$state = GroupLifeMapper::ACTIVE;
			}
			foreach ($snapshot['admission']['sources'] as $source) {
				// Renewed from the gen read under this mutex: sameLife() above accepted a renewal since the external
				// phase, which changes nothing this save relied on.
				if ($source['groupId'] !== $gid && $source['state'] === GroupLifeMapper::ACTIVE
					&& !$this->life->transition($source['groupId'], (string)($this->life->get($source['groupId'])['gen'] ?? ''),
						GroupLifeMapper::ACTIVE, bin2hex(random_bytes(16)))) {
					throw new SnapshotInvalidated();
				}
			}
			$this->groupGrants->replaceGroup($gid, $grants);
			foreach ($snapshot['admission']['cuts'] as $uid) {
				$this->effects->revokeUserLocal($uid);
			}
			$outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_GROUP,
				$current['revision'], $revision, null, null, [['gid' => $gid, 'key' => PolicyEventPayload::GROUP_KEY,
					'before' => PolicyEventPayload::groupSnapshot($stored['state'] ?? null, $snapshot['current']),
					'after' => PolicyEventPayload::groupSnapshot($state, $grants)]]));
			$outcome = ['changed' => true, 'state' => $state, 'revoked' => count($snapshot['admission']['cuts'])];
			return true;
		});
		return $preview === null ? $outcome : ['revision' => $result['revision']] + $outcome;
	}
}
