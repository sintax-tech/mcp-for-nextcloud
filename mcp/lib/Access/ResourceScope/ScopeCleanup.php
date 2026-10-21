<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;

use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

use OCA\Mcp\Access\Policy\DecimalCounter;

use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removal of a deleted account's resource scope (contracts B.3, F3), qualified by the policy revision so a scope saved
 * after the deletion started is never removed. The removal is a writer command under the global mutex that advances
 * the revision when it removes anything, and it runs only for the deletion event the core delivers: no pre-event
 * handler takes the mutex, and no directory read happens under it.
 *
 * | Signal | Under the mutex |
 * | --- | --- |
 * | UserDeletedEvent with the revision R_b read by its BeforeUserDeletedEvent, both outside a transaction | remove the user's scopes with policy_rev <= R_b; later ones stay |
 * | UserDeletedEvent without R_b (its Before ran inside a transaction or failed), or itself inside a transaction | nothing |
 * | the account's Before without an After (the deletion failed or was rolled back) | nothing: there is no event |
 * | the job | nothing: it removes nothing and writes no policy; it only reports (see reconcile()) |
 *
 * Why the job never removes. Proving that an account is gone is not in the app's hands: a directory backend that is
 * unreachable may answer "no such user", and the account can be recreated between the proof and the write without any
 * policy change to order it. Lifting a restriction on that proof could leave an existing account unrestricted, while
 * keeping a stale scope is harmless, because an account recreated under the id is only restricted. The event is a
 * strong proof from the core itself; when it is lost, the scope stays and the job counts it.
 *
 * Declared behavior: an account deleted and recreated under the same id, with the event lost or deferred, keeps the old
 * restriction. It only narrows, and it is never lifted for lack of proof. A scope saved for the recreated account after
 * R_b was read is a later revision and is never removed by the event either. There is no unqualified delete by user and
 * no authorization generation in these tables.
 */
class ScopeCleanup {
    /** Users the job looks at per run; the rest wait for the next run. */
    public const RECONCILE_BATCH = 200;
    /** App config key (lazy) holding the user_key after which the next diagnostic pass starts. */
    public const CURSOR_KEY = 'scope_cleanup_cursor';

    public function __construct(
        private PolicyTransaction $writer,
        private ResourceScopeMapper $scopes,
        private IUserManager $users,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {}

    /**
     * UserDeletedEvent, on time: removes the scopes the account had when its deletion started.
     *
     * @param string $uid the deleted account
     * @param string $revision R_b, the revision its BeforeUserDeletedEvent read in autocommit
     * @return list<string> the modules whose scope was removed
     * @throws \InvalidArgumentException for an invalid user id or revision
     * @throws PolicyUnavailable when the storage fails, a stored row is invalid, or the outcome is uncertain: nothing is
     *     removed on the storage failures, and the scope is kept (the job's diagnostic counts it; nothing retries)
     */
    public function deleted(string $uid, string $revision): array {
        if (!PolicyEventPayload::validUid($uid)) {
            throw new \InvalidArgumentException('Invalid user');
        }
        DecimalCounter::validate($revision, DecimalCounter::MAX);
        $removed = [];
        $this->writer->runInternal([$uid], static fn () => null, function () use ($uid, $revision, &$removed): bool {
            $removed = $this->removeLocal($uid, $revision);
            return $removed !== [];
        });
        if ($removed !== []) {
            $this->logger->info('MCP resource scope removed with its deleted account', ['app' => 'mcp', 'target' => $uid, 'modules' => $removed, 'result' => 'removed']);
        }
        return $removed;
    }

    /**
     * The job's diagnostic pass over the users that still have a scope. It removes nothing, writes no policy, takes no
     * mutex and opens no transaction: it reads each user's account outside any transaction, as the writer's external
     * phase does, and reports. Pages of $limit follow a cursor (the only thing it stores, an app config value), so users
     * that keep failing never hold the batch; it wraps around when a page comes back short. One user that fails, or a
     * row whose id does not match its digest, is skipped and counted without stopping the others.
     *
     * Logged at info, per pass: users looked at, kept_account_exists (the account exists), absent (the account is gone
     * by a fresh read, so its scope is orphaned: an event was lost or deferred, and the scope stays), blocked_or_failed
     * (an id that fails its digest, or an account that cannot be read), and orphan_item_users (users keeping item rows
     * without their mode, which have no effect and are left in place).
     *
     * @param int $limit most users looked at
     * @return int users with a scope whose account was found absent in this pass
     */
    public function reconcile(int $limit = self::RECONCILE_BATCH): int {
        try {
            $cursor = $this->appConfig->getValueString('mcp', self::CURSOR_KEY, '', true);
            $page = $this->scopes->usersWithModes($limit, $cursor);
            if ($page === [] && $cursor !== '') {
                $page = $this->scopes->usersWithModes($limit);
            }
            $this->appConfig->setValueString('mcp', self::CURSOR_KEY,
                count($page) < $limit ? '' : $page[count($page) - 1]['userKey'], true);
        } catch (Throwable $e) {
            $this->logger->warning('Cannot list the users with a resource scope', ['app' => 'mcp', 'exception' => $e]);
            return 0;
        }
        $kept = 0;
        $absent = 0;
        $blocked = 0;
        foreach ($page as ['userKey' => $userKey, 'userId' => $uid]) {
            try {
                if (!PolicyEventPayload::validUid($uid) || IdentityKey::user($uid) !== $userKey) {
                    ++$blocked;
                    $this->logger->warning('A stored resource scope names an id that does not match its key; it stays', ['app' => 'mcp']);
                    continue;
                }
                if ($this->exists($uid)) {
                    ++$kept;
                } else {
                    ++$absent;
                }
            } catch (Throwable $e) {
                ++$blocked;
                $this->logger->warning('The account of a resource scope could not be read', ['app' => 'mcp', 'target' => $uid, 'exception' => $e]);
            }
        }
        if ($page !== []) {
            $this->diagnose(count($page), $kept, $absent, $blocked);
        }
        return $absent;
    }

    /**
     * Under the mutex: the user's scopes saved at or before $revision, each with its items, and nothing newer.
     *
     * @return list<string> the modules removed
     * @throws PolicyUnavailable when a stored row is invalid or inconsistent: nothing was removed
     */
    private function removeLocal(string $uid, string $revision): array {
        try {
            return $this->scopes->removeUpTo($uid, $revision);
        } catch (\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid stored resource scope', previous: $e);
        }
    }

    /**
     * Reads the account through this process's user manager and backends, outside any transaction. Their answers may
     * be cached, including an absence left by a deletion the caller rolled back. The job never removes scopes (B3),
     * so an incorrect absent answer affects only its diagnostic count. An account, including a disabled one, counts
     * as existing if either process-local answer reports it.
     *
     * @throws PolicyUnavailable when the account cannot be read
     */
    private function exists(string $uid): bool {
        try {
            return $this->users->get($uid) !== null || $this->users->userExists($uid);
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read the account of a resource scope', previous: $e);
        }
    }

    private function diagnose(int $seen, int $kept, int $absent, int $blocked): void {
        try {
            $orphans = $this->scopes->orphanItemUsers();
        } catch (Throwable) {
            $orphans = null;
        }
        $this->logger->info('MCP resource scope diagnostic pass', ['app' => 'mcp', 'users' => $seen, 'kept_account_exists' => $kept,
            'absent' => $absent, 'blocked_or_failed' => $blocked, 'orphan_item_users' => $orphans]);
    }
}
