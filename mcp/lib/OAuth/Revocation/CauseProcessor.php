<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Revocation;

use OCA\Mcp\Access\Grants\Groups\GroupLifecycle;

use OCA\Mcp\OAuth\Revocation\DeferredQueue;

use OCA\Mcp\OAuth\Revocation\CauseClassifier;

use OCA\Mcp\OAuth\Revocation\CredentialCommands;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

use Closure;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\RecheckMapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Consumes recheck causes (contracts §3.3.1, §3.3.4) at every point the contract names, through the one writer
 * protocol: the After event of a cause captured by its Before, the preflight of a request of the user (Basic, Bearer,
 * token endpoint), issuance (through the classifier it hands to runCredential()) and the reconciler job.
 *
 * It also owns the processing of the facts captured inside a caller's transaction (contracts §3.3.6): never a query
 * while the connection is inside a transaction; at the first safe point of the process (flush(), called at the entry
 * of the app's listeners, routes and jobs) or, as a last resort, at shutdown. Every Throwable of that processing is
 * contained and logged; it writes no output, never exits and never changes the exit code. A group deletion or creation
 * captured there is processed the same way, as needs_confirm of its lifecycle (GroupLifecycle::needsConfirm()).
 */
class CauseProcessor {
    /** Users the reconciler processes per run; the rest wait for the next run or for their own preflight. */
    public const RECONCILE_BATCH = 200;
    /** App config key (lazy) holding the user_key after which the next reconciler run starts. */
    public const CURSOR_KEY = 'reconcile_cursor';

    private bool $flushing = false;

    public function __construct(
        private CredentialCommands $writer,
        private RecheckMapper $rechecks,
        private CauseClassifier $classifier,
        private DeferredQueue $deferred,
        private IDBConnection $db,
        private LoggerInterface $logger,
        private IAppConfig $appConfig,
        private GroupLifecycle $groups,
    ) {}

    /** @return Closure(string, list<array>): array<string, bool> the classification issuance consumes causes with */
    public function classifier(): Closure {
        return fn (string $uid, array $causes): array => $this->classifier->classify($uid, $causes);
    }

    /**
     * Before validating or issuing anything for $uid: the deferred facts of this process, then every pending cause
     * of the user. A cause that cannot be classified fails the request closed (503) and revokes nothing.
     *
     * @param string $uid the user of the request
     * @throws PolicyUnavailable when a cause cannot be read, classified or consumed
     */
    public function preflight(string $uid): void {
        $this->flush();
        try {
            $this->writer->processCauses($uid, $this->classifier());
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid recheck cause owner', previous: $e);
        }
    }

    /**
     * The After of one captured cause: only that cause, and only with its gen; a cause already consumed, or replaced
     * by a newer native operation, is left alone. Inside a caller's transaction it is deferred instead.
     *
     * @param string $uid the user
     * @param string $causeKey cause_key the Before recorded
     * @param string $gen gen the Before recorded
     * @throws PolicyUnavailable when the cause cannot be classified or consumed; it stays for the reconciler
     */
    public function after(string $uid, string $causeKey, string $gen): void {
        if ($this->db->inTransaction()) {
            $this->deferred->dated($uid, $causeKey, $gen, $this->shutdown(...));
            return;
        }
        $this->writer->processCauses($uid, $this->classifier(),
            static fn (array $cause): bool => $cause['causeKey'] === $causeKey && $cause['gen'] === $gen);
    }

    /**
     * A user cause seen inside a caller's transaction, before any row could be stored: reconciled later without a
     * date (contracts §3.3.6).
     */
    public function deferUndated(string $uid): void {
        $this->deferred->undated($uid, $this->shutdown(...));
    }

    /**
     * A group deletion or creation seen inside a caller's transaction: at the next safe point its lifecycle, if any,
     * goes to needs_confirm; nothing is deleted and no GroupDeletedEvent is simulated (contracts §3.3.6).
     */
    public function deferGroup(string $gid): void {
        $this->deferred->group($gid, $this->shutdown(...));
    }

    /**
     * The reconciler: up to $limit users with pending causes, after the cursor the previous run left, each in its own
     * commands. A user whose causes cannot be classified keeps them; the cursor still moves past it, so such users
     * never hold the batch, and it wraps around when a page comes back short. One failing user never stops the others.
     *
     * @param int $limit most users processed
     * @return int causes consumed
     */
    public function reconcile(int $limit = self::RECONCILE_BATCH): int {
        $this->flush();
        try {
            $cursor = $this->appConfig->getValueString('mcp', self::CURSOR_KEY, '', true);
            $users = $this->rechecks->pendingUsers($limit, $cursor);
            if ($users === [] && $cursor !== '') {
                $users = $this->rechecks->pendingUsers($limit);
            }
            $this->appConfig->setValueString('mcp', self::CURSOR_KEY,
                count($users) < $limit ? '' : IdentityKey::user($users[count($users) - 1]), true);
        } catch (Throwable $e) {
            $this->logger->warning('Cannot list the pending recheck causes', ['app' => 'mcp', 'exception' => $e]);
            return 0;
        }
        $consumed = 0;
        foreach ($users as $uid) {
            try {
                $consumed += $this->writer->processCauses($uid, $this->classifier());
            } catch (Throwable $e) {
                $this->logger->warning('Recheck causes of a user stay pending', ['app' => 'mcp', 'target' => $uid, 'exception' => $e]);
            }
        }
        return $consumed;
    }

    /**
     * The first safe point of the process: processes the deferred facts if the connection is outside any
     * transaction, and does nothing otherwise. Never throws. An item whose state cannot be read is kept.
     */
    public function flush(): void {
        if ($this->flushing || $this->deferred->isEmpty()) {
            return;
        }
        $this->flushing = true;
        try {
            if ($this->db->inTransaction()) {
                return;
            }
            foreach ($this->deferred->take() as $item) {
                try {
                    $this->process($item);
                } catch (PolicyUnavailable $e) {
                    $this->deferred->keep($item);
                    $this->logger->info('Deferred recheck kept for the next safe point', ['app' => 'mcp', 'target' => self::target($item), 'exception' => $e]);
                } catch (Throwable $e) {
                    $this->logger->error('Deferred recheck dropped', ['app' => 'mcp', 'target' => self::target($item), 'exception' => $e]);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('Deferred recheck processing failed', ['app' => 'mcp', 'exception' => $e]);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * The last resort, registered with register_shutdown_function. With the connection still inside a transaction
     * the caller never concluded it, and closing the connection rolls it back: every item is dropped
     * (deferred_dropped_open_transaction), with no durable effect. Otherwise the items are processed once; what
     * still cannot be read is dropped (deferred_dropped_unavailable). Contains every Throwable and prints nothing.
     */
    public function shutdown(): void {
        try {
            if ($this->db->inTransaction()) {
                foreach ($this->deferred->take() as $item) {
                    $this->logger->warning('deferred_dropped_open_transaction', ['app' => 'mcp', 'target' => self::target($item)]);
                }
                return;
            }
            $this->flush();
            foreach ($this->deferred->take() as $item) {
                $this->logger->warning('deferred_dropped_unavailable', ['app' => 'mcp', 'target' => self::target($item)]);
            }
        } catch (Throwable $e) {
            try {
                $this->logger->error('Deferred recheck shutdown failed', ['app' => 'mcp', 'exception' => $e]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param array{kind:string, uid:string, causeKey:?string, gen:?string, gid?:string} $item
     * @throws PolicyUnavailable when the state cannot be read; the item is kept
     */
    private function process(array $item): void {
        if ($item['kind'] === DeferredQueue::GROUP) {
            // Committed or rolled back, the app cannot tell: suspend the group's policy, never delete it.
            $this->groups->needsConfirm((string)$item['gid']);
            return;
        }
        if ($item['kind'] === DeferredQueue::DATED) {
            // A cause stored on time, whose After came inside the transaction: the dated protocol (Q1 applies).
            $this->after($item['uid'], (string)$item['causeKey'], (string)$item['gen']);
            return;
        }
        // No date: an eligible user is never cut, the old credential included (exception to Q1, §6.2.1).
        if (!$this->writer->reconcileUndated($item['uid'], $this->classifier->undated(...))) {
            $this->logger->info('deferred_unconfirmed', ['app' => 'mcp', 'target' => $item['uid']]);
            return;
        }
    }

    /** @return string the user or group an item is about, for the log */
    private static function target(array $item): string {
        return $item['kind'] === DeferredQueue::GROUP ? (string)($item['gid'] ?? '') : $item['uid'];
    }
}
