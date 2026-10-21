<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Listener;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Grants\Groups\GroupLifecycle;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\BeforeGroupCreatedEvent;
use OCP\Group\Events\BeforeGroupDeletedEvent;
use OCP\Group\Events\GroupCreatedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Observes the group events of contracts §3.3.2 and §3.3.5 for the GID lifecycle, with the same rules as the user
 * events: it never throws into the core, never takes the policy mutex in a pre-event (nor flushes deferred work there)
 * and never refuses or delays a native operation; inside a caller's transaction it runs no SQL at all, and the fact is
 * reconciled later as needs_confirm, never as a confirmed deletion (§3.3.6).
 *
 * - BeforeGroupDeletedEvent(G), when G has a lifecycle, in three steps written without the mutex (GroupLifeMapper
 *   explains why that is safe): needs_confirm with a new gen (markSuspended()), then, once, the causes (U, G) of the
 *   credential holders who are in G and would not be eligible without it (a new random gen per cause), then
 *   pending_delete with a new gen (markPendingDelete(), conditioned on the gen of the first step). The first step is
 *   conditioned on the gen just read; losing it means the lifecycle moved in between (a group save that relied on G,
 *   or an issuance or reset G admitted, each of which renews G's gen in its commit: LifecycleHolds::hold()),
 *   and the Before reads again and retries, at most MARK_ROUNDS rounds in all.
 *
 *   Suspend first, then capture (F1 of the second reading of 1.1.0, 15/10/2026; ticket 07 recorded the opposite
 *   order): with the issuance renewing G's gen under its mutex, every issuance G admitted either committed before the
 *   suspension (which then lost its gen, was retried, and the capture after it sees the credential) or finds G no
 *   longer active under its mutex and refuses. The capture still discriminates: "eligible without G" removes G by
 *   overlay and reads the same before or after the suspension, and a user eligible only through G reads unknown now,
 *   which the relevance filter keeps. No cause exists before the suspension, so no issuance can consume one while G
 *   still admits, and a cause recorded here is never written again (review R2 of ticket 08).
 *
 *   A mark that loses its gen to a lifecycle that still exists (the administrator confirmed G's policy during the
 *   capture, or something G admitted renewed its gen) is not the end of the Before: it goes back to the round, suspends
 *   G again by its new gen and captures again only the holders still without a cause (M1 of the final confirmation of
 *   F1/F2, 15/10/2026); a cleared policy is the only loss it accepts. Exhausting MARK_ROUNDS rounds, in either step,
 *   falls to the Q1 capture below, so the Before never gives up in silence.
 *
 *   Only a complete capture moves G on to pending_delete, the one state the job purges (RF1 of the re-reading): when
 *   the capture fails (the live holders cannot be listed, or a cause cannot be written), or the process dies first, G
 *   stays needs_confirm in storage, so neither the job nor the After purges it; what G admitted stays unknown (503),
 *   never valid, until an administrator decides.
 *
 *   If the Before cannot suspend within MARK_ROUNDS rounds, it records a cause for every live holder in G, without the
 *   relevance filter (Q1: an ambiguous removal revokes), and the After suspends G. Accepted residual of that fallback
 *   (coordinator, 15/10/2026): G is still active until the After, so a credential G admits between that capture and the
 *   After has no cause; it is unusable while G is needs_confirm, and becomes valid again only if an administrator
 *   explicitly confirms G's policy.
 * - GroupDeletedEvent(G): purges G's rows and lifecycle under the mutex only if it still carries the gen the Before
 *   wrote (a policy saved since keeps its new gen), then the protocol for each cause the Before recorded. When the
 *   Before could not record its gen, the lifecycle goes to needs_confirm instead of being left active.
 * - BeforeGroupCreatedEvent(name): reads the revision R_c (one local SELECT, no mutex).
 * - GroupCreatedEvent(G), when G has a lifecycle: with R_c captured for this exact id, purges a policy saved at or
 *   before R_c and keeps one saved after; without it (a backend that names the group itself, a failed read, a Before
 *   inside a transaction), needs_confirm.
 *
 * Creating another group while one is being deleted is never refused: the handlers only observe.
 */
class GroupLifecycleListener implements IEventListener {
    /** Rounds a deletion's Before judges the holders and tries to mark pending_delete. */
    public const MARK_ROUNDS = 3;

    /**
     * group_key => what the Before of a deletion in this process left: the gen it wrote (string), false when G had a
     * lifecycle but the gen could not be written, null when G had none; and the causes it recorded.
     *
     * @var array<string, array{gid:string, gen:string|false|null, causes:list<array{uid:string, gen:string}>}>
     */
    private array $deleting = [];
    /** @var array<string, array{name:string, revision:?string}> group_key => R_c captured by a creation's Before */
    private array $creating = [];

    public function __construct(
        private GroupLifecycle $lifecycle,
        private CauseProcessor $causes,
        private GroupLifeMapper $life,
        private GroupGrantsMapper $grants,
        private RecheckMapper $rechecks,
        private PolicyStateMapper $state,
        private OAuthStore $store,
        private AuthUsersMapper $authUsers,
        private GroupSourceClassifier $classifier,
        private PolicyResolver $resolver,
        private IUserManager $users,
        private IDBConnection $db,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void {
        try {
            if ($event instanceof BeforeGroupDeletedEvent) {
                $this->beforeDelete($event->getGroup());
            } elseif ($event instanceof GroupDeletedEvent) {
                $this->afterDelete($event->getGroup());
            } elseif ($event instanceof BeforeGroupCreatedEvent) {
                $this->beforeCreate($event->getName());
            } elseif ($event instanceof GroupCreatedEvent) {
                $this->afterCreate($event->getGroup());
            }
        } catch (Throwable $e) {
            try {
                $this->logger->warning('Group lifecycle signal left to the reconciler', ['app' => 'mcp', 'exception' => $e]);
            } catch (Throwable) {
            }
        }
    }

    private function beforeDelete(IGroup $group): void {
        $gid = $group->getGID();
        $key = IdentityKey::group($gid);
        if ($this->db->inTransaction()) {
            unset($this->deleting[$key]);
            $this->causes->deferGroup($gid);
            return;
        }
        // No flush here: it may take the mutex.
        $this->deleting[$key] = ['gid' => $gid, 'gen' => false, 'causes' => []];
        /** @var array<string, array{uid:string, gen:string}> one gen per holder for this native operation */
        $causes = [];
        try {
            $this->markPending($group, $key, $causes);
        } finally {
            // Recorded causes reach the After even when a write below throws.
            $this->deleting[$key]['causes'] = array_values($causes);
        }
    }

    /** @param array<string, array{uid:string, gen:string}> $causes receives every cause recorded */
    private function markPending(IGroup $group, string $key, array &$causes): void {
        $gid = $group->getGID();
        // One round: read the lifecycle, suspend it, capture the holders still without a cause, mark pending_delete.
        // Losing the suspension or the mark means the lifecycle moved in between (a policy save or confirmation, or an
        // issuance or reset G admitted), and the next round starts again from what is stored (at most MARK_ROUNDS).
        for ($round = 0; $round < self::MARK_ROUNDS; ++$round) {
            $row = $this->life->get($gid);
            if ($row === null) {
                // No lifecycle (any more). Causes already recorded must still reach the After, which skips a null gen.
                $this->deleting[$key]['gen'] = $causes === [] ? null : bin2hex(random_bytes(16));
                return;
            }
            $suspended = bin2hex(random_bytes(16));
            if (!$this->life->markSuspended($gid, $row['gen'], $suspended)) {
                continue;
            }
            // Suspended (durable): an issuance G admitted committed before (and is listed here) or refuses after it.
            $complete = true;
            // The first round captures only an active group that grants eligibility. A later round captures whatever
            // it reads: G may have admitted a consent while it was active again, and then moved to needs_confirm
            // before this round (review O1 of M1). Such a group judges nobody, so the holders are not filtered by
            // what they would be without G; record() still skips every holder that already has a cause.
            $judged = $row['state'] === GroupLifeMapper::ACTIVE && $this->grantsEligibility($gid);
            if ($judged || $round > 0) {
                try {
                    $complete = $this->record($gid, $this->holders($group, $judged), $causes);
                } catch (Throwable $e) {
                    $complete = false;
                    $this->logger->warning('Credential holders of a deleted group not listed; the group stays suspended',
                        ['app' => 'mcp', 'target' => $gid, 'exception' => $e]);
                }
            }
            if (!$complete) {
                // needs_confirm stays in storage: neither the job nor the After purges it without the holders' causes.
                return;
            }
            $pending = bin2hex(random_bytes(16));
            try {
                $moved = $this->life->markPendingDelete($gid, $suspended, $pending);
            } catch (Throwable $e) {
                $this->logger->warning('Captured group deletion left suspended', ['app' => 'mcp', 'target' => $gid, 'exception' => $e]);
                return;
            }
            if ($moved) {
                $this->deleting[$key]['gen'] = $pending;
                return;
            }
            if ($this->life->get($gid) === null) {
                // The policy was cleared since: no row carries this gen, so the After purges nothing and still
                // processes the causes recorded here.
                $this->deleting[$key]['gen'] = $pending;
                $this->logger->info('Group policy cleared during its deletion is kept', ['app' => 'mcp', 'target' => $gid]);
                return;
            }
            // Another gen or state is stored (an administrator confirmed the policy during the capture, say): G admits
            // again, so a credential it admitted since has no cause. Suspend it again and capture again, only those
            // without one (a recorded cause is never written twice, review R2 of ticket 08).
            $this->logger->info('Group policy changed during its capture; capturing again', ['app' => 'mcp', 'target' => $gid]);
        }
        // The lifecycle kept moving: every live holder in G, judged by nothing, so its After revokes by Q1. Never a
        // silent give-up: a cause already recorded stays, the rest are added, and the After suspends G.
        try {
            $this->record($gid, $this->holders($group, false), $causes);
        } catch (Throwable $e) {
            $this->logger->warning('Credential holders of a deleted group not listed; their causes are left to observed use',
                ['app' => 'mcp', 'target' => $gid, 'exception' => $e]);
        }
    }

    /**
     * Records the cause (U, G) of each holder not recorded yet in this Before (the same native operation, contracts
     * §3.3.1), once, after the mark or in the Q1 fallback. A recorded cause is never written again: an issuance may
     * consume it (cutting the old generations and issuing a new one that does not rely on G, which no longer admits),
     * and writing it back would let the After cut that credential (review R2 of ticket 08). Its gen stays in the
     * context for the After, which finds nothing to do once it is consumed. A failing write is logged and reported,
     * so the caller suspends G instead of purging it.
     *
     * @param list<string> $holders uids
     * @param array<string, array{uid:string, gen:string}> $causes the causes recorded so far, by a digest of the uid
     * @return bool whether every holder has a cause
     */
    private function record(string $gid, array $holders, array &$causes): bool {
        $complete = true;
        foreach ($holders as $uid) {
            $key = hash('sha256', $uid);
            if (isset($causes[$key])) {
                continue;
            }
            $gen = bin2hex(random_bytes(16));
            try {
                $this->rechecks->record($uid, RecheckMapper::REASON_GROUP, $gid, $gen, $this->time->getTime());
                $causes[$key] = ['uid' => $uid, 'gen' => $gen];
            } catch (Throwable $e) {
                $complete = false;
                $this->logger->warning('Recheck cause of a group deletion not recorded', ['app' => 'mcp', 'target' => $uid, 'exception' => $e]);
            }
        }
        return $complete;
    }

    private function afterDelete(IGroup $group): void {
        $gid = $group->getGID();
        $key = IdentityKey::group($gid);
        $captured = $this->deleting[$key] ?? null;
        unset($this->deleting[$key]);
        if ($this->db->inTransaction()) {
            // Commit or rollback cannot be known: needs_confirm later, never a purge.
            $this->causes->deferGroup($gid);
            foreach ($captured['causes'] ?? [] as $cause) {
                $this->causes->after($cause['uid'], $key, $cause['gen']);
            }
            return;
        }
        $this->causes->flush();
        if ($captured !== null && $captured['gen'] === null) {
            // G had no lifecycle at its Before: nothing to purge.
            return;
        }
        if ($captured === null || $captured['gen'] === false) {
            // The Before could not record its gen (or never ran here): suspend, never purge on an unknown gen.
            $this->lifecycle->needsConfirm($gid);
        } else {
            $this->lifecycle->deleted($gid, $captured['gen']);
        }
        // After the purge, so "eligible without G" is read with G gone; one failing cause stays for the reconciler.
        foreach ($captured['causes'] ?? [] as $cause) {
            try {
                $this->causes->after($cause['uid'], $key, $cause['gen']);
            } catch (Throwable $e) {
                $this->logger->warning('Recheck cause left to the reconciler', ['app' => 'mcp', 'target' => $cause['uid'], 'exception' => $e]);
            }
        }
    }

    private function beforeCreate(string $name): void {
        $key = IdentityKey::group($name);
        $this->creating[$key] = ['name' => $name, 'revision' => null];
        if ($this->db->inTransaction()) {
            return;
        }
        // One local SELECT; no mutex, no flush.
        $this->creating[$key]['revision'] = $this->state->state()['revision'];
    }

    private function afterCreate(IGroup $group): void {
        $gid = $group->getGID();
        $key = IdentityKey::group($gid);
        $captured = $this->creating[$key] ?? null;
        unset($this->creating[$key]);
        if ($this->db->inTransaction()) {
            $this->causes->deferGroup($gid);
            return;
        }
        $this->causes->flush();
        // R_c counts only for the id its Before announced, byte for byte; a backend that names the group differently
        // leaves no usable R_c, and the lifecycle goes to needs_confirm.
        $revision = $captured !== null && $captured['name'] === $gid ? $captured['revision'] : null;
        $this->lifecycle->created($gid, $revision);
    }

    /** Whether G's stored policy grants eligibility; a failed read counts as yes (the causes are then recorded). */
    private function grantsEligibility(string $gid): bool {
        try {
            return in_array('eligible', $this->grants->forGroups([$gid])[0]['grants']['_system'] ?? [], true)
                && $this->classifier->trusted($gid);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Credential holders in G who may be eligible now and would not be without G (contracts §3.3.2): the ones whose
     * credential a deletion of G must re-check. Cheapest filters first, so the resolutions run only for the few that
     * matter: owners of a live code or grant (not expired, current epoch), intersected with G's member list (one call
     * per serving backend), whose highest generation is above their cut; then the account and two resolutions. A
     * failed member list keeps every live holder; a failed read for one holder records its cause anyway; an account
     * already gone is the account cause's business.
     *
     * @param bool $relevant false keeps every live, uncut holder in G with an account, without the two resolutions
     * @return list<string> their uids
     * @throws \Throwable when the live holders cannot be listed (the caller logs it and still writes pending_delete)
     */
    private function holders(IGroup $group, bool $relevant = true): array {
        $gid = $group->getGID();
        $live = $this->store->liveHolders($this->time->getTime(), $this->state->state()['auth_epoch']);
        if ($live === []) {
            return [];
        }
        try {
            $live = array_intersect_key($live, $this->classifier->members($gid));
        } catch (Throwable) {
            // Unknown membership: the resolutions below decide; over-report rather than omit.
        }
        $kept = [];
        foreach ($live as ['uid' => $uid, 'gen' => $highest]) {
            try {
                if (self::cutBelow($highest, $this->authUsers->generations($uid)['revoked_below'])) {
                    continue;
                }
                if ($this->users->get($uid) === null) {
                    continue;
                }
                if (!$relevant || ($this->eligible($uid, []) !== false && $this->eligible($uid, [$gid]) !== true)) {
                    $kept[] = $uid;
                }
            } catch (Throwable) {
                $kept[] = $uid;
            }
        }
        return $kept;
    }

    /** Whether every credential of the holder is already cut (its highest generation is at or below revoked_below). */
    private static function cutBelow(string $highest, string $revokedBelow): bool {
        try {
            return DecimalCounter::compare(DecimalCounter::validate($highest, DecimalCounter::MAX), $revokedBelow) <= 0;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return bool|null the resolved eligibility without the given groups; null when unknown */
    private function eligible(string $uid, array $without): ?bool {
        $context = $this->resolver->begin($uid, [], $without, false);
        try {
            return $context->eligible()->allowed;
        } finally {
            $context->close();
        }
    }
}
