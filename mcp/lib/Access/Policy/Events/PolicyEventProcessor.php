<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Events;

use OCA\Mcp\Access\Policy\PolicyLimits;

use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers policy events after their commit (contracts §3.2): cleanup events run the qualified physical delete of a
 * revocation, audit events go to the server log. Delivery happens right after the command's commit, in a bounded
 * batch before token requests and in the daily background job; it may repeat, and the event id correlates
 * duplicates; a delivered event is marked so and purged a day later. A failed delivery stays pending with one more attempt and is retried with an exponential delay capped
 * at one hour, without a final limit. Receipts are never delivered: they stay pending until they expire and the job
 * removes them.
 *
 * The revocation itself is logical and already committed (the user's cut or the global epoch), so a delivery that
 * never runs leaves revoked credentials in the tables but never makes them usable.
 */
class PolicyEventProcessor {
    /** Events one preflight delivers before a token request. */
    public const PREFLIGHT_BATCH = 10;
    /** Events per batch of the background job. */
    public const JOB_BATCH = 100;
    /** Batches one job run delivers at most; the next run continues. */
    public const JOB_ROUNDS = 20;
    /** Seconds a delivered event stays as a trace before the job purges it. */
    public const DELIVERED_KEPT = 86400;
    /** Attempts after which a failing delivery is reported as a warning on every retry. */
    public const WARN_AFTER = 5;
    private const DELIVERED = [PolicyEventsMapper::KIND_CLEANUP, PolicyEventsMapper::KIND_AUDIT];

    public function __construct(
        private PolicyEventsMapper $events,
        private OAuthStore $oauth,
        private LoggerInterface $logger,
        private ITimeFactory $time,
    ) {}

    /**
     * Delivers the events one command just committed. Never throws: the command succeeded whatever happens here.
     *
     * @param list<array{id:string, kind:string, payload:array}> $events the committed outbox, in emission order
     * @return bool whether every delivered event went through (false: cleanup or audit is pending)
     */
    public function afterCommit(array $events): bool {
        $delivered = true;
        foreach ($events as $event) {
            if (in_array($event['kind'], self::DELIVERED, true)) {
                $delivered = $this->deliver($event) && $delivered;
            }
        }
        return $delivered;
    }

    /**
     * Delivers due pending events, fewest attempts and oldest first. Never throws.
     *
     * @param int $limit events to read at most, 1..500
     * @return int events delivered
     */
    public function flush(int $limit = self::PREFLIGHT_BATCH): int {
        return $this->round($limit)['delivered'];
    }

    /**
     * The daily job: expired credentials, pending deliveries in bounded batches, expired receipts and previews.
     * Idempotent; when it does not run, validation still refuses every expired or revoked credential.
     */
    public function maintenance(): void {
        $now = $this->time->getTime();
        try {
            $this->oauth->purgeExpired($now);
        } catch (Throwable $e) {
            $this->warn('Cannot purge expired OAuth credentials', ['app' => 'mcp', 'exception' => $e]);
        }
        // Stop at a short batch, or at a batch that delivered nothing: the rest is not due yet or keeps failing.
        for ($round = 0; $round < self::JOB_ROUNDS; ++$round) {
            ['read' => $read, 'delivered' => $delivered] = $this->round(self::JOB_BATCH);
            if ($read < self::JOB_BATCH || $delivered === 0) {
                break;
            }
        }
        try {
            $this->events->purge(PolicyEventsMapper::KIND_RECEIPT, $now - PolicyLimits::RECEIPT_TTL);
            $this->events->purge(PolicyEventsMapper::KIND_PREVIEW, $now - PolicyLimits::PREVIEW_TTL);
            $this->events->purgeDelivered($now - self::DELIVERED_KEPT);
        } catch (Throwable $e) {
            $this->warn('Cannot purge expired policy receipts', ['app' => 'mcp', 'exception' => $e]);
        }
    }

    /**
     * One batch. An invalid stored row is counted as a failed attempt and skipped, so it never blocks the queue.
     *
     * @return array{read:int, delivered:int} rows read (valid or not) and events delivered
     */
    private function round(int $limit): array {
        $invalid = [];
        try {
            $pending = $this->events->pendingOf(self::DELIVERED, $limit, $invalid);
        } catch (Throwable $e) {
            $this->warn('Cannot read pending policy events', ['app' => 'mcp', 'exception' => $e]);
            return ['read' => 0, 'delivered' => 0];
        }
        foreach ($invalid as $id) {
            $this->warn('Skipping an invalid stored policy event', ['app' => 'mcp', 'eventId' => $id]);
            try {
                $this->events->recordAttempt($id);
            } catch (Throwable) {
            }
        }
        $now = $this->time->getTime();
        $delivered = 0;
        foreach ($pending as $event) {
            if (self::due($event['attempts'], $event['createdAt'], $now) && $this->deliver($event)) {
                ++$delivered;
            }
        }
        return ['read' => count($pending) + count($invalid), 'delivered' => $delivered];
    }

    /**
     * Whether a pending event is due: at once on its first delivery, then after a delay that doubles from one minute
     * per failed attempt, each step capped at one hour.
     *
     * @param int $attempts failed deliveries so far
     * @param int $createdAt creation time of the event
     * @param int $now current time
     */
    public static function due(int $attempts, int $createdAt, int $now): bool {
        $doubling = min(max($attempts, 0), 6);
        // 60 + 120 + ... for the doubling steps, then one hour per further attempt.
        $delay = 60 * ((1 << $doubling) - 1) + max($attempts - 6, 0) * 3600;
        return $now >= $createdAt + $delay;
    }

    /** @param array{id:string, kind:string, payload:array, attempts?:int} $event */
    private function deliver(array $event): bool {
        try {
            $payload = PolicyEventPayload::validate($event['kind'], $event['payload']);
            if ($event['kind'] === PolicyEventsMapper::KIND_CLEANUP) {
                if ($payload['scope'] === PolicyEventPayload::SCOPE_USER) {
                    $this->oauth->deleteUpToGeneration($payload['uid'], $payload['cut']);
                } else {
                    $this->oauth->deleteBelowEpoch($payload['epoch']);
                }
                $this->logger->info('MCP credentials revoked', ['app' => 'mcp', 'eventId' => $event['id'],
                    'scope' => $payload['scope'], 'target' => $payload['uid'], 'cut' => $payload['cut'],
                    'epoch' => $payload['epoch'], 'result' => 'cleaned']);
            } else {
                $this->logger->info('MCP policy changed', ['app' => 'mcp', 'eventId' => $event['id'],
                    'actor' => $payload['actor'], 'scope' => $payload['action'],
                    // A group save names its group; every other change names its user (null: app-wide).
                    'target' => array_values(array_unique(array_map(static fn (array $change): ?string => $change['gid'] ?? $change['uid'], $payload['changes']))),
                    'fromRevision' => $payload['fromRevision'], 'revision' => $payload['revision'],
                    'receiptId' => $payload['receiptId'], 'undoOf' => $payload['undoOf'], 'result' => 'committed']);
            }
            $this->events->complete($event['id']);
            return true;
        } catch (Throwable $e) {
            $attempts = ($event['attempts'] ?? 0) + 1;
            try {
                $this->events->recordAttempt($event['id']);
            } catch (Throwable) {
            }
            $context = ['app' => 'mcp', 'eventId' => $event['id'], 'kind' => $event['kind'], 'attempts' => $attempts, 'exception' => $e];
            if ($attempts >= self::WARN_AFTER) {
                $this->warn('MCP policy event delivery keeps failing; revoked credentials stay unusable', $context);
            } else {
                try {
                    $this->logger->info('MCP policy event delivery failed; it will be retried', $context);
                } catch (Throwable) {
                    // The durable pending event remains the failure record when the log backend is unavailable.
                }
            }
            return false;
        }
    }

    /** Diagnostics cannot turn committed work or deferred cleanup into a failed command. */
    private function warn(string $message, array $context): void {
        try {
            $this->logger->warning($message, $context);
        } catch (Throwable) {
            // Delivery state and attempts remain durable; diagnostic failure cannot undo a commit.
        }
    }

}
