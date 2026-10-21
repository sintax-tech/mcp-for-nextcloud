<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Revocation;

use Closure;
use Throwable;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyUsers;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Transactions\SnapshotInvalidated;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\Commands\GrantMutations;
use OCA\Mcp\Access\Grants\Groups\LifecycleHolds;
use OCA\Mcp\OAuth\Revocation\CredentialCuts;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCP\IConfig;

final class CredentialCommands {
    public function __construct(private PolicyTransaction $transaction, private AuthUsersMapper $authUsers, private RecheckMapper $rechecks, private IConfig $config, private PolicyUsers $users, private GrantMutations $mutations, private CredentialCuts $cuts, private LifecycleHolds $holds) {}

    public function runCredential(string $uid, Closure $prepare, Closure $apply, ?Closure $classify = null): array {
        return $this->transaction->runInternal([$uid], function (array $observed) use ($uid, $prepare, $classify): array {
            $causes = $this->rechecks->causes($uid);
            $before = ['generations' => $this->authUsers->generations($uid), 'causes' => $causes];
            $classified = $classify === null || $causes === [] ? [] : self::classified($causes, $classify($uid, $causes));
            return ['before' => $before, 'classified' => $classified, 'snapshot' => $prepare($observed)];
        }, function (array $prepared, PolicyAttempt $attempt) use ($uid, $apply): bool {
            $current = $attempt->state();
            if ($this->authUsers->generations($uid) !== $prepared['before']['generations']
                || $this->rechecks->causes($uid) !== $prepared['before']['causes']) {
                throw new SnapshotInvalidated();
            }
            $consumed = $this->cuts->consume($attempt, $uid, $prepared['classified']) > 0;
            return $apply($prepared['snapshot'], $current, $this->authUsers->generations($uid), $attempt) || $consumed;
        });
    }

    public function processCauses(string $uid, Closure $classify, ?Closure $select = null): int {
        if (!PolicyEventPayload::validUid($uid)) {
            throw new \InvalidArgumentException('Invalid user');
        }
        $choose = static fn (array $causes): array => $select === null ? $causes : array_values(array_filter($causes, $select));
        try {
            if ($choose($this->rechecks->causes($uid)) === []) {
                return 0;
            }
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read recheck causes', previous: $e);
        }
        $consumed = 0;
        $this->transaction->runInternal([$uid], function () use ($uid, $classify, $choose): array {
            $causes = $this->rechecks->causes($uid);
            $chosen = $choose($causes);
            return ['causes' => $causes, 'classified' => self::classified($chosen, $chosen === [] ? [] : $classify($uid, $chosen))];
        }, function (array $prepared, PolicyAttempt $attempt) use ($uid, &$consumed): bool {
            if ($this->rechecks->causes($uid) !== $prepared['causes']) {
                throw new SnapshotInvalidated();
            }
            $consumed = $this->cuts->consume($attempt, $uid, $prepared['classified']);
            return $consumed > 0;
        });
        return $consumed;
    }

    public function consent(string $uid, Closure $admit, Closure $insertCode, ?Closure $classify = null): bool {
        $allowed = false;
        $this->runCredential($uid, function () use ($uid, $admit): ?array {
            $this->users->assertKnown($uid);
            return $admit();
        }, function (?array $lifecycles, array $current, array $generations, PolicyAttempt $attempt) use ($uid, $insertCode, &$allowed): bool {
            $individual = $this->config->getUserValueForUsers(GrantPolicy::APP, GrantPolicy::ELIGIBLE_KEY, [$uid])[$uid] ?? null;
            // An individual Allow admits by itself; a group admits only while the user has no individual value.
            $allowed = $lifecycles !== null
                && $this->config->getAppValue(GrantPolicy::APP, GrantPolicy::SERVICE_KEY, '0') === '1'
                && ($individual === '1' || ($individual === null && $lifecycles !== []));
            if (!$allowed) {
                return false;
            }
            $this->holds->hold($attempt, $lifecycles);
            $changed = $this->mutations->write($attempt, $uid, GrantPolicy::CONNECTED_KEY, '1') !== null;
            $insertCode($this->authUsers->issue($uid), $current['auth_epoch']);
            return $changed;
        }, $classify);
        return $allowed;
    }

    /** Revalidate undated classification against the revision and generations before making a cut. */
    public function reconcileUndated(string $uid, Closure $classify): bool {
        if (!PolicyEventPayload::validUid($uid)) {
            throw new \InvalidArgumentException('Invalid user');
        }
        $cut = false;
        $this->runCredential($uid, static fn (): bool => $classify($uid),
            function (bool $revoke, array $current, array $generations, PolicyAttempt $attempt) use ($uid, &$cut): bool {
                $cut = $revoke;
                if ($revoke) {
                    $this->cuts->user($attempt, $uid);
                }
                return false;
            });
        return $cut;
    }

    public function revokeCredentials(string $uid): void {
        if (!PolicyEventPayload::validUid($uid)) {
            throw new \InvalidArgumentException('Invalid user');
        }
        $this->transaction->runInternal([$uid], fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($uid): bool {
            $this->cuts->user($attempt, $uid);
            return false;
        });
    }

    public function revokeObserved(string $uid, string $observed): void {
        if (!PolicyEventPayload::validUid($uid)) {
            throw new \InvalidArgumentException('Invalid user');
        }
        DecimalCounter::validate($observed, DecimalCounter::MAX);
        $this->transaction->runInternal([$uid], fn () => null, function ($snapshot, PolicyAttempt $attempt) use ($uid, $observed): bool {
            $this->cuts->user($attempt, $uid, $observed);
            return false;
        });
    }

    private static function classified(array $causes, array $classification): array {
        $classified = [];
        foreach ($causes as $cause) {
            if (!is_bool($classification[$cause['causeKey']] ?? null)) {
                throw new \LogicException('Every recheck cause must be classified');
            }
            $classified[] = ['causeKey' => $cause['causeKey'], 'gen' => $cause['gen'], 'cut' => $classification[$cause['causeKey']]];
        }
        return $classified;
    }
}
