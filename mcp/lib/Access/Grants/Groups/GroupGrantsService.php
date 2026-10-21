<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;

use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;

use OCA\Mcp\Access\Grants\Groups\GroupGrantCommands;

use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;

/**
 * Group policy commands of the admin API (contracts §2.3, §3.3.5): the read of one group's policy, its preview and
 * its save. The writer owns the transaction, the holders rule and every write; this service supplies the external
 * phase it runs outside any transaction, which is where every directory read happens:
 *
 * - the source predicate, read fresh through the backends (GroupSourceClassifier::trusted());
 * - the member list, enumerated from the backends that serve the group, whose hash a save compares with its preview;
 * - for a withdrawal of _system/eligible, which credential holders lose their last source: a holder in the group
 *   (fresh membership) who is not ineligible now and is determined not eligible without the group. An individual
 *   Allow or another granting group keeps the holder eligible, so its credentials are kept. A holder whose
 *   eligibility without the group is unknown (another group of indeterminate policy, contracts §3.3.5) makes the
 *   whole save unavailable (503): nothing is written and nobody is cut, and the administrator retries once the
 *   source is readable (coordinator decision of 15/10/2026). A holder unknown now only because of the group being
 *   withdrawn (the group itself is suspended) is determined without it, so the rule above applies;
 * - the lifecycle (state and gen, or absence) of every group those resolutions read, which the writer compares again
 *   under its mutex and renews, so a source that kept a holder cannot move unseen before the commit.
 *
 * The enumeration is complete only for a group that can grant: exactly one backend, named 'Database', which lists
 * every member. Any other group gets a non-empty set refused (422); clearing its whole policy stays allowed, because
 * its rows grant nothing and the cuts come from the holders, not from the enumeration (contracts §3.3.5: the rows stay
 * "until the administrator confirms or clears").
 */
class GroupGrantsService {
    public function __construct(
        private GroupGrantCommands $writer,
        private GroupSourceClassifier $classifier,
        private PolicyResolver $resolver,
        private GroupGrantsMapper $grants,
        private GroupLifeMapper $life,
    ) {}

    /**
     * The stored policy of one group and whether it can grant now.
     *
     * @param string $gid the group, exact bytes
     * @return array{groupId:string, trusted:bool, state:?string, grants:array<string, list<string>>}
     * @throws \InvalidArgumentException for an invalid group id
     * @throws PolicyUnavailable when the storage or the directory cannot be read
     */
    public function state(string $gid): array {
        if (!PolicyEventPayload::validUid($gid)) {
            throw new \InvalidArgumentException('Invalid group');
        }
        try {
            $grants = $this->grants->forGroups([$gid])[0]['grants'];
            $life = $this->life->get($gid);
        } catch (\UnexpectedValueException|\OCP\DB\Exception $e) {
            throw new PolicyUnavailable('Cannot read the group policy', previous: $e);
        }
        return ['groupId' => $gid, 'trusted' => $this->classifier->trusted($gid), 'state' => $life['state'] ?? null, 'grants' => $grants];
    }

    /**
     * @see PolicyTransaction::previewGroupGrants()
     * @throws \InvalidArgumentException|GroupSourceUntrusted|PolicyConflict|PolicyUnavailable
     */
    public function preview(string $expectedRevision, string $actor, string $gid, mixed $grants): array {
        return $this->writer->previewGroupGrants($expectedRevision, $actor, $gid, $grants, $this->admit(...));
    }

    /**
     * @see PolicyTransaction::setGroupGrants()
     * @throws \InvalidArgumentException|ReceiptNotFound|ReceiptExpired|GroupSourceUntrusted|PolicyConflict|PolicyUnavailable
     */
    public function save(string $expectedRevision, string $actor, string $gid, mixed $grants, string $previewId): array {
        return $this->writer->setGroupGrants($expectedRevision, $actor, $gid, $grants, $previewId, $this->admit(...));
    }

    /**
     * The external phase of a group preview or save; never under the mutex.
     *
     * @param array{gid:string, grants:array, current:array, withdraws:bool, holders:list<string>} $command
     * @return array{trusted:bool, members:list<string>, cuts:list<string>}
     * @throws PolicyUnavailable when a backend, a membership or an eligibility cannot be read
     */
    public function admit(array $command): array {
        $gid = $command['gid'];
        $trusted = $this->classifier->trusted($gid);
        $members = array_values($this->classifier->members($gid));
        $cuts = $sources = [];
        if ($command['withdraws']) {
            foreach ($command['holders'] as $uid) {
                if ($this->loses($uid, $gid, $sources)) {
                    $cuts[] = $uid;
                }
            }
        }
        return ['trusted' => $trusted, 'members' => $members, 'cuts' => $cuts, 'sources' => array_values($sources)];
    }

    /**
     * Whether a holder loses its last source of eligibility when $gid stops granting it.
     *
     * @param array<string, array{groupId:string, state:?string, gen:?string}> $sources receives the lifecycle of every
     *     candidate group the resolutions read, by group digest
     * @throws PolicyUnavailable when the membership or the policy cannot be read, or the holder's eligibility without
     *     the group is unknown
     */
    private function loses(string $uid, string $gid, array &$sources): bool {
        $context = $this->resolver->begin($uid, [], [], false);
        try {
            $candidates = $context->candidates();
            $now = $context->eligible()->allowed;
        } finally {
            $context->close();
        }
        if (!in_array($gid, array_column($candidates, 'groupId'), true) || $now === false) {
            return false;
        }
        $context = $this->resolver->begin($uid, [], [$gid], false);
        try {
            $candidates = [...$candidates, ...$context->candidates()];
            $without = $context->eligible()->allowed;
        } finally {
            $context->close();
        }
        foreach ($candidates as $candidate) {
            $sources[\OCA\Mcp\Db\IdentityKey::group($candidate['groupId'])] ??= ['groupId' => $candidate['groupId'],
                'state' => $candidate['state'] ?? null, 'gen' => $candidate['gen'] ?? null];
        }
        if ($without === null) {
            throw new PolicyUnavailable('Eligibility of a credential holder without the group is unknown');
        }
        return !$without;
    }
}
