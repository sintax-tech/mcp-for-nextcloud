<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Listener;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\ResourceScope\ScopeCleanup;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\BeforeUserRemovedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Observes the user events of contracts §3.3.2 without ever being a fence: it never throws into the core, never
 * takes the policy mutex in a pre-event (no deferred work is flushed there either: that runs writer commands) and
 * never refuses or delays a native operation. Inside a caller's transaction it runs no SQL at all: the fact is kept
 * in memory and reconciled later without a date (§3.3.6).
 *
 * - BeforeUserDeletedEvent(U), when U holds a credential or may be eligible: records the account cause with a new
 *   random gen, and marks U as being deleted in this process.
 * - BeforeUserRemovedEvent(G, U), unless U is being deleted with its account cause still pending: when G is a trusted
 *   group whose active policy grants eligibility, U is still in G, may be eligible now and would not inherit
 *   eligibility without G, records the cause (U, G) with a new gen. The duplicate Before of User::delete wrapping Group::removeUser keeps that gen.
 *   "Inherit" ignores an individual Allow (RF2 of the re-reading of F2, 15/10/2026): the Allow may be removed by a reset
 *   whose external phase already read G as the source U inherits from, and the cause is what makes that reset see the
 *   removal. Processing the cause re-reads eligibility: while the Allow exists it consumes the cause without a cut,
 *   which advances the revision and so replays a reset prepared before it (the replay reads U outside G and cuts);
 *   once the Allow is gone it cuts. A user who stays eligible is never cut.
 * - UserRemovedEvent(G, U): the protocol for the cause (U, G) with the captured gen, and nothing else.
 * - UserDeletedEvent(U): the protocol for the account cause with the captured gen (account gone: cut). When its Before
 *   could not store the cause, the deletion is reconciled without a date instead (account gone: cut), never dropped.
 * - The resource scope of the account (contracts B.3, F3): BeforeUserDeletedEvent outside a transaction reads the policy
 *   revision R_b in autocommit, whatever the account's credentials; UserDeletedEvent outside a transaction removes the
 *   scopes saved at or before R_b (ScopeCleanup::deleted()). A Before or an After inside a transaction, a Before that
 *   could not read R_b, and a deletion with no After remove nothing: the scope is kept, and the job's diagnostic pass
 *   (ScopeCleanup::reconcile()) only counts it. The handler takes no mutex before the event and runs no SQL inside a
 *   caller's transaction.
 *
 * A read that fails while testing relevance records the cause anyway: the reconciler repeats the test. A cause whose
 * After never arrives (a failed or interrupted operation) stays pending for the preflight and the reconciler (Q1).
 */
class UserCauseListener implements IEventListener {
    /**
     * uid => the account cause of a deletion in this process: its gen; false when U was relevant but the cause could not
     * be stored; null when U was irrelevant or the Before came inside a transaction (already deferred).
     *
     * @var array<string, string|false|null>
     */
    private array $deleting = [];
    /** @var array<string, array{uid:string, gid:string, gen:string}> pair => the cause recorded by its Before */
    private array $removals = [];
    /** @var array<string, string|null> uid => R_b read by the Before of a deletion in this process; null when none could be */
    private array $scopeRevisions = [];

    public function __construct(
        private CauseProcessor $causes,
        private RecheckMapper $rechecks,
        private OAuthStore $store,
        private GroupGrantsMapper $grants,
        private GroupLifeMapper $life,
        private GroupSourceClassifier $classifier,
        private PolicyResolver $resolver,
        private IDBConnection $db,
        private ITimeFactory $time,
        private LoggerInterface $logger,
        private PolicyStateMapper $state,
        private ScopeCleanup $scopeCleanup,
    ) {}

    public function handle(Event $event): void {
        try {
            if ($event instanceof BeforeUserDeletedEvent) {
                $this->beforeDelete($event->getUser());
            } elseif ($event instanceof BeforeUserRemovedEvent) {
                $this->beforeRemove($event->getGroup(), $event->getUser());
            } elseif ($event instanceof UserRemovedEvent) {
                $this->afterRemove($event->getGroup(), $event->getUser());
            } elseif ($event instanceof UserDeletedEvent) {
                $this->afterDelete($event->getUser());
            }
        } catch (Throwable $e) {
            try {
                $this->logger->warning('Recheck cause left to the reconciler', ['app' => 'mcp', 'exception' => $e]);
            } catch (Throwable) {
            }
        }
    }

    private function beforeDelete(IUser $user): void {
        $uid = $user->getUID();
        $this->scopeRevisions[$uid] = $this->captureRevision();
        if ($this->db->inTransaction()) {
            $this->deleting[$uid] = null;
            $this->causes->deferUndated($uid);
            return;
        }
        // A new native operation: always a new gen (contracts §3.3.1). No flush here: it may take the mutex.
        $this->deleting[$uid] = null;
        if (!$this->accountRelevant($uid)) {
            return;
        }
        // Relevant: if the record below fails, the After must still revoke (reconciled without a date).
        $this->deleting[$uid] = false;
        $gen = bin2hex(random_bytes(16));
        $this->rechecks->record($uid, RecheckMapper::REASON_ACCOUNT, null, $gen, $this->time->getTime());
        $this->deleting[$uid] = $gen;
    }

    private function beforeRemove(IGroup $group, IUser $user): void {
        $uid = $user->getUID();
        if ($this->db->inTransaction()) {
            $this->causes->deferUndated($uid);
            return;
        }
        // No flush here: it may take the mutex. Part of an account deletion whose account cause is still pending is
        // covered by it (§3.3.3); a mark left by a deletion that failed or stored nothing silences nothing.
        $deleting = $this->deleting[$uid] ?? null;
        if (is_string($deleting) && $this->stillPending($uid, IdentityKey::accountCause(), $deleting)) {
            return;
        }
        $gid = $group->getGID();
        $pair = self::pair($uid, $gid);
        $open = $this->removals[$pair]['gen'] ?? null;
        if ($open !== null && $this->stillPending($uid, IdentityKey::group($gid), $open)) {
            // The gen recorded for this pair is still the stored one and nothing consumed it: the nested Before of the
            // same native operation, or a later removal whose earlier After was lost. Either way no credential was
            // issued since (issuance would have consumed the cause), so keeping the gen protects nothing less.
            return;
        }
        unset($this->removals[$pair]);
        if (!$this->removalRelevant($group, $user)) {
            return;
        }
        $gen = bin2hex(random_bytes(16));
        $this->rechecks->record($uid, RecheckMapper::REASON_GROUP, $gid, $gen, $this->time->getTime());
        $this->removals[$pair] = ['uid' => $uid, 'gid' => $gid, 'gen' => $gen];
    }

    private function afterRemove(IGroup $group, IUser $user): void {
        $pair = self::pair($user->getUID(), $group->getGID());
        $captured = $this->removals[$pair] ?? null;
        unset($this->removals[$pair]);
        if (!$this->db->inTransaction()) {
            $this->causes->flush();
        }
        if ($captured === null) {
            // Not relevant, already covered by the account cause, or the duplicate After of a consumed cause.
            return;
        }
        $this->causes->after($captured['uid'], IdentityKey::group($captured['gid']), $captured['gen']);
    }

    private function afterDelete(IUser $user): void {
        $uid = $user->getUID();
        $gen = $this->deleting[$uid] ?? null;
        unset($this->deleting[$uid]);
        $revision = $this->scopeRevisions[$uid] ?? null;
        unset($this->scopeRevisions[$uid]);
        if (!$this->db->inTransaction()) {
            $this->causes->flush();
        }
        $this->removeScopes($uid, $revision);
        if ($gen === false) {
            // Relevant, but its Before could not store the cause: reconcile without a date (account gone: cut).
            $this->causes->deferUndated($uid);
            $this->causes->flush();
            return;
        }
        if ($gen === null) {
            // Irrelevant at its Before, or captured inside a transaction (already deferred): nothing dated to do.
            return;
        }
        $this->causes->after($uid, IdentityKey::accountCause(), $gen);
    }

    /**
     * R_b: the revision, read in autocommit before the account goes. Never inside a caller's transaction (no SQL there)
     * and never an error: without it the scope of this deletion is kept, and only the job's diagnostic counts it.
     */
    private function captureRevision(): ?string {
        if ($this->db->inTransaction()) {
            return null;
        }
        try {
            return $this->state->state()['revision'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The scope cleanup of a deletion that was seen on time: its own writer command, its failure never stopping the
     * cause protocol that follows; on failure the scope is kept. Inside a caller's transaction, or without R_b, it does
     * nothing.
     */
    private function removeScopes(string $uid, ?string $revision): void {
        if ($revision === null || $this->db->inTransaction()) {
            return;
        }
        try {
            $this->scopeCleanup->deleted($uid, $revision);
        } catch (Throwable $e) {
            try {
                $this->logger->warning('Resource scope of a deleted account kept, its cleanup failed; only the job diagnostic counts it', ['app' => 'mcp', 'target' => $uid, 'exception' => $e]);
            } catch (Throwable) {
            }
        }
    }

    /** U holds a code or grant row, or is not known to be ineligible. A failed read is relevant. */
    private function accountRelevant(string $uid): bool {
        try {
            return $this->store->hasCredentials($uid) || $this->eligible($uid, []) !== false;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * G grants eligibility through an active, trusted policy, U is still in G (or that is unknown), U is not known to
     * be ineligible, and U would not inherit eligibility without G, whatever its individual value (see the class). A
     * failed read is relevant.
     */
    private function removalRelevant(IGroup $group, IUser $user): bool {
        $gid = $group->getGID();
        try {
            $grants = $this->grants->forGroups([$gid])[0]['grants'] ?? [];
            if (!in_array('eligible', $grants['_system'] ?? [], true)
                || ($this->life->get($gid)['state'] ?? null) !== GroupLifeMapper::ACTIVE
                || !$this->classifier->trusted($gid)
                || $group->inGroup($user) === false) {
                return false;
            }
            // G matters only if U may be eligible with it and would not be without it; a user ineligible either way
            // (an account already gone from its backend, for instance) cannot consent, so nothing needs re-checking.
            return $this->eligible($user->getUID(), []) !== false && $this->eligibilityWithoutOwnValue($user->getUID(), [$gid]) !== true;
        } catch (Throwable) {
            return true;
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

    /** @return bool|null the eligibility inherited without the given groups, ignoring the individual value; null when unknown */
    private function eligibilityWithoutOwnValue(string $uid, array $without): ?bool {
        $context = $this->resolver->begin($uid, [], $without, false);
        try {
            return $context->inheritedEligibility()->allowed;
        } finally {
            $context->close();
        }
    }

    /** Whether the stored cause still carries the gen this process recorded for it. */
    private function stillPending(string $uid, string $key, string $gen): bool {
        foreach ($this->rechecks->causes($uid) as $cause) {
            if ($cause['causeKey'] === $key) {
                return $cause['gen'] === $gen;
            }
        }
        return false;
    }

    private static function pair(string $uid, string $gid): string {
        return IdentityKey::user($uid) . "\0" . IdentityKey::group($gid);
    }
}
