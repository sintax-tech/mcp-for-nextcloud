<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCP\DB\Exception as DatabaseException;

use Closure;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Transactions\SnapshotInvalidated;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Receipts\ReceiptExpired;
use OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound;
use OCA\Mcp\OAuth\Revocation\CredentialCuts;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Mcp\Access\Grants\Groups\GroupSourceUntrusted;

final class GroupGrantCommands {
    public function __construct(private PolicyTransaction $transaction, private GroupLifeMapper $life, private GroupGrantsMapper $groupGrants, private AuthUsersMapper $authUsers, private OAuthStore $oauth, private ITimeFactory $time, private PolicyEventsMapper $events, private CredentialCuts $cuts) {}

    public function previewGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, Closure $admit): array {
        return $this->groupCommand($expectedRevision, $actor, $gid, PolicyEventPayload::groupGrants($grants), null, $admit);
    }

    public function setGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, string $previewId, Closure $admit): array {
        DecimalCounter::validate($expectedRevision);
        $grants = PolicyEventPayload::groupGrants($grants);
        $preview = $this->preview($previewId, $actor);
        if ($preview['gid'] !== $gid || $preview['grants'] !== $grants) {
            throw new \InvalidArgumentException('The save differs from its preview');
        }
        if ($preview['revision'] !== $expectedRevision) {
            throw new PolicyConflict();
        }
        return $this->groupCommand($expectedRevision, $actor, $gid, $grants, $preview, $admit);
    }

    private function groupCommand(string $expected, string $actor, string $gid, array $grants, ?array $preview, Closure $admit): array {
        if (!PolicyEventPayload::validUid($actor) || !PolicyEventPayload::validUid($gid)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $outcome = [];
        $result = $this->transaction->runAdmin($expected, [], function (array $observed) use ($gid, $grants, $preview, $admit): array {
            // Local reads first, H0 included, before anything remote (contracts §2.3).
            $now = $this->time->getTime();
            $life = self::life($this->life->get($gid));
            $current = $this->groupGrants->forGroups([$gid])[0]['grants'];
            $withdraws = PolicyEventPayload::grantsEligibility($current) && !PolicyEventPayload::grantsEligibility($grants);
            $holders = $withdraws ? $this->holders($now, $observed['auth_epoch']) : [];
            $candidates = [];
            foreach ($holders as $holder) {
                if (!self::allCut($holder['gen'], $holder['revoked_below'])) {
                    $candidates[] = $holder['uid'];
                }
            }
            $admission = self::admission($admit(['gid' => $gid, 'grants' => $grants, 'current' => $current,
                'withdraws' => $withdraws, 'holders' => $candidates]), $withdraws ? $candidates : []);
            if ($grants !== [] && !$admission['trusted']) {
                throw new GroupSourceUntrusted();
            }
            $memberHash = PolicyEventPayload::memberHash($admission['members']);
            if ($preview !== null && ($preview['memberHash'] !== $memberHash || !self::sameLife($preview['life'], $life))) {
                throw new PolicyConflict();
            }
            return ['now' => $now, 'life' => $life, 'current' => $current, 'withdraws' => $withdraws, 'holders' => $holders,
                'admission' => $admission, 'memberHash' => $memberHash];
        }, function (array $snapshot, PolicyAttempt $attempt) use ($actor, $gid, $grants, $preview, &$outcome): bool {
            $current = $attempt->state();
            $outbox = $attempt->outbox();
            $stored = $this->life->get($gid);
            if (!self::sameLife(self::life($stored), $snapshot['life']) || $this->groupGrants->forGroups([$gid])[0]['grants'] !== $snapshot['current']) {
                throw new SnapshotInvalidated();
            }
            if ($snapshot['withdraws'] && $this->holders($snapshot['now'], $current['auth_epoch']) !== $snapshot['holders']) {
                throw new SnapshotInvalidated();
            }
            foreach ($snapshot['admission']['sources'] as $source) {
                if ($source['groupId'] !== $gid && !self::sameLife(self::life($this->life->get($source['groupId'])), self::life($source['gen'] === null ? null : $source))) {
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
            $revision = DecimalCounter::increment($current['revision']);
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
                $this->cuts->user($attempt, $uid);
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

    private function holders(int $now, string $epoch): array {
        $holders = [];
        foreach ($this->oauth->liveHolders($now, $epoch) as $key => ['uid' => $uid, 'gen' => $gen]) {
            try {
                $holders[$key] = ['uid' => $uid, 'gen' => $gen] + $this->authUsers->generations($uid);
            } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
                throw new PolicyUnavailable('Cannot read the credential holders', previous: $e);
            }
        }
        ksort($holders, SORT_STRING);
        return $holders;
    }

    private static function allCut(string $highest, string $revokedBelow): bool {
        try {
            return DecimalCounter::compare(DecimalCounter::validate($highest, DecimalCounter::MAX), $revokedBelow) <= 0;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private static function admission(mixed $admission, array $holders): array {
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

    private static function life(?array $row): ?array {
        return $row === null ? null : ['state' => $row['state'], 'gen' => $row['gen']];
    }

    private static function sameLife(?array $a, ?array $b): bool {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return $a['state'] === $b['state'] && ($a['gen'] === $b['gen'] || $a['state'] === GroupLifeMapper::ACTIVE);
    }

    private function preview(string $previewId, string $actor): array {
        if (!PolicyEventsMapper::validId($previewId)) {
            throw new ReceiptNotFound();
        }
        try {
            $event = $this->events->find($previewId);
            $payload = $event === null || $event['kind'] !== PolicyEventsMapper::KIND_PREVIEW ? null
                : PolicyEventPayload::validate(PolicyEventsMapper::KIND_PREVIEW, $event['payload']);
        } catch (\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid stored preview', previous: $e);
        } catch (DatabaseException $e) {
            throw new PolicyUnavailable(previous: $e);
        }
        if ($payload === null || $payload['actor'] !== $actor) {
            throw new ReceiptNotFound();
        }
        if ($this->time->getTime() >= $event['createdAt'] + PolicyLimits::PREVIEW_TTL) {
            throw new ReceiptExpired();
        }
        return $payload;
    }
}
