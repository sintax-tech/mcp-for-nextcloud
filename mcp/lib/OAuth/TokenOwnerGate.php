<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Access\Grants\GrantPolicy;
use Closure;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\OAuth\Revocation\CredentialCommands;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a token owner may still use OAuth credentials: the account exists and is enabled and the MCP
 * connection policy (global switch, eligibility, individual or by group, personal connection) allows it, on a fresh
 * resolution. An eligibility that is unknown (it depends only on a group of indeterminate policy) is a failed read. A failed read is not an answer: it fails the request (PolicyUnavailable) and revokes nothing.
 * An owner observed unusable is revoked by a cut at the generation read before the check (contracts §3.1, (iii)),
 * so re-enabling later requires a new consent, and a consent made after that read is never touched by it.
 *
 * It is also where OAuth meets the recheck causes of the owner (contracts §3.3.4): validation processes them before
 * reading the generations it checks (preflight()), and issuance consumes them in its own transaction (causes()).
 */
class TokenOwnerGate {
    public function __construct(
        private IUserManager $userManager,
        private GrantPolicy $policy,
        private CredentialCommands $credentials,
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private CauseProcessor $causes,
    ) {}

    /**
     * Processes the owner's pending recheck causes before a credential of theirs is validated, so the generations
     * read next already include any cut they demand.
     *
     * @param string $uid owner of the credential
     * @throws PolicyUnavailable when a cause cannot be classified or consumed; nothing is accepted nor revoked then
     */
    public function preflight(string $uid): void {
        $this->causes->preflight($uid);
    }

    /** @return Closure(string, list<array>): array<string, bool> the classification issuance consumes causes with */
    public function causes(): Closure {
        return $this->causes->classifier();
    }

    /**
     * The fresh check alone, without any write.
     *
     * @param string $uid owner of the credential
     * @return IUser|null the usable owner, or null when the account or the policy refuses it
     * @throws PolicyUnavailable when the account or the policy cannot be read
     */
    public function usable(string $uid): ?IUser {
        try {
            $this->userConfig->clearCache($uid, false);
            $this->appConfig->clearCache(false);
            $user = $this->userManager->get($uid);
            return $user !== null && $user->isEnabled() && $this->policy->canConnect($uid) ? $user : null;
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read the credential owner', previous: $e);
        }
    }

    /**
     * The check of an issuance (consent, exchange, refresh), in the external phase of its writer command: the account
     * exists and is enabled, and GrantPolicy::admission() admits the user. The groups that admitted the user come
     * back with their lifecycle gens, for LifecycleHolds::hold() under the mutex.
     *
     * @param string $uid the user a credential is issued to
     * @param bool $connection also require the personal connection (exchange, refresh); a consent sets it itself
     * @return list<array{groupId:string, gen:string}>|null null when the account or the policy refuses the user
     * @throws PolicyUnavailable when the account or the policy cannot be read, or the eligibility is unknown
     */
    public function admission(string $uid, bool $connection): ?array {
        try {
            $user = $this->userManager->get($uid);
            if ($user === null || !$user->isEnabled()) {
                return null;
            }
            return $this->policy->admission($uid, $connection);
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read the credential owner', previous: $e);
        }
    }

    /**
     * @param string $uid owner of the credential
     * @param string $observed the owner's issue_seq, read before this check
     * @return IUser|null the usable owner, or null after revoking every generation up to $observed
     * @throws PolicyUnavailable when the account or the policy cannot be read; nothing is revoked then
     */
    public function owner(string $uid, string $observed): ?IUser {
        $user = $this->usable($uid);
        if ($user === null) {
            $this->revokeObserved($uid, $observed);
        }
        return $user;
    }

    /**
     * Cuts at the observed generation. The credential is refused whether or not the cut commits: validation repeats
     * the check on every use, so a failed cut only leaves the tombstone for the next observation.
     */
    public function revokeObserved(string $uid, string $observed): void {
        try {
            $this->credentials->revokeObserved($uid, $observed);
        } catch (Throwable $e) {
            $this->logger->warning('Cannot record the revocation of an unusable credential owner', ['app' => 'mcp', 'exception' => $e]);
        }
    }
}
