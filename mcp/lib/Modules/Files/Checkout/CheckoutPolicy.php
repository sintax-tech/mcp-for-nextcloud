<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IUserSession;

final class CheckoutPolicy {
    private const MODULE = 'files';
    private const OPERATION = 'edit';
    private const OPERATION_CREATE = 'create';

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private ITimeFactory $time,
        private GrantPolicy $policy,
        private CheckoutTokenStore $store,
        private IUserManager $userManager,
        private FilesAccessGuard $visibilityGuard,
        private ?GuestRestrictions $guests = null,
    ) {}

    /** Keep all grant and resource checks of one authenticated link use in its owner's snapshot. */
    public function within(?CheckoutToken $row, \Closure $work): mixed {
        return $row === null ? $work() : $this->policy->within($row->userId, $work);
    }

    /**
     * What makes a link worth reading the body for: it exists, it is of the kind the route expects, the session agrees
     * with it, and it is neither spent nor expired. Nothing is spent here; consume() still decides the single use.
     *
     * @param CheckoutToken|null $row the row of the presented token, null when there is none
     * @param string $kind CheckoutToken::KIND_UPLOAD or KIND_CREATE, as the prefix of the token announced
     * @return Response|null the refusal, or null when the body may be read
     */
    public function assertLink(?CheckoutToken $row, string $kind): void {
        if ($row === null || $row->kind !== $kind) {
            throw new CheckoutFailure(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenWrongKind());
        }
        $session = $this->userSession->getUser();
        if ($session !== null && $session->getUID() !== $row->userId) {
            throw new CheckoutFailure(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
        }
        // The account is asked before the body is read, so a disabled one costs neither a temporary file nor the link.
        if (!$this->ownerUsable($row->userId)) {
            throw new CheckoutFailure(Http::STATUS_FORBIDDEN, CheckoutMessages::checkoutRevoked());
        }
        // A spent or expired link says so before anything else, even when the file of a create link now exists.
        if ($row->used || $row->expiresAt <= $this->time->getTime()) {
            throw new CheckoutFailure(Http::STATUS_GONE, $kind === CheckoutToken::KIND_CREATE ? CheckoutMessages::createTokenSpent() : CheckoutMessages::tokenSpent());
        }
        return;
    }

    /**
     * Everything a request has to pass before the link stops being reusable: the token exists, the session
     * agrees with it, the policy still allows it, and the node it names is still there with the same id.
     * None of these has touched the file, so none of them may cost the agent its link.
     *
     * Each refusal maps to the HTTP status the agent can act on (404 unknown or foreign token, 403 revoked).
     *
     * @param CheckoutToken|null $row the row of the presented token, null when there is none
     * @param string $kind CheckoutToken::KIND_DOWNLOAD or KIND_UPLOAD
     * @return array{0: CheckoutToken, 1: File}|Response the token with its node, or the refusal
     */
    public function resolve(?CheckoutToken $row, string $kind, ?\Closure $configureLanguage = null): ResolvedCheckout {
        if ($row === null || $row->kind !== $kind) {
            throw new CheckoutFailure(Http::STATUS_NOT_FOUND, $kind === CheckoutToken::KIND_UPLOAD ? CheckoutMessages::tokenWrongKind() : CheckoutMessages::tokenInvalid());
        }
        if ($kind === CheckoutToken::KIND_CREATE) {
            $uid = $row->userId;
            $this->restrictGuestOwner($uid);
            if (!$this->ownerUsable($uid) || !$this->policy->canConnect($uid) || !$this->policy->granted($uid, self::MODULE, self::OPERATION_CREATE)) {
                throw new CheckoutFailure(Http::STATUS_FORBIDDEN, CheckoutMessages::checkoutRevoked());
            }
            $this->visibilityGuard->assertScopeReadable();
            $configureLanguage?->__invoke($uid);
            return new ResolvedCheckout($row, null);
        }
        // A link is the credential, so the session may only ever agree with it.
        $session = $this->userSession->getUser();
        if ($session !== null && $session->getUID() !== $row->userId) {
            throw new CheckoutFailure(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
        }
        $uid = $row->userId;
        $this->restrictGuestOwner($uid);
        if (!$this->ownerUsable($uid) || !$this->policy->canConnect($uid) || !$this->policy->granted($uid, self::MODULE, self::OPERATION)) {
            throw new CheckoutFailure(Http::STATUS_FORBIDDEN, CheckoutMessages::checkoutRevoked());
        }
        $this->visibilityGuard->assertScopeReadable();
        $configureLanguage?->__invoke($uid);
        try {
            $file = NodeAccess::requireFile(NodeAccess::get($this->rootFolder->getUserFolder($uid), $row->path, $this->visibilityGuard));
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (\Throwable) {
            throw new CheckoutFailure(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
        }
        if ((int)$file->getId() !== $row->fileId) {
            throw new CheckoutFailure(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
        }
        if (!$this->visibilityGuard->isVisible($file)) {
            throw new CheckoutFailure(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
        }
        return new ResolvedCheckout($row, $file);
    }

    /**
     * Whether this request writes as the owner of the link. A capability request has no user session, and files_lock
     * then refuses even the owner's own manual lock, so the check has to refuse it too.
     *
     * @param string $uid owner of the link
     * @return bool true when the session user is that owner
     */
    public function actsAsUser(string $uid): bool {
        return $this->userSession->getUser()?->getUID() === $uid;
    }

    /** Apply sessionless owner restrictions before resolving a file; denial must not consume the link. */
    private function restrictGuestOwner(string $uid): void {
        $owner = $this->userManager->get($uid);
        if ($owner !== null && $this->guests !== null && !$this->guests->applyToOwner($owner)) {
            throw new CheckoutFailure(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
        }
    }

    /**
     * Whether the account the link was issued to still exists and is enabled. The policy only knows the app's switches:
     * an administrator disabling or deleting the account does not touch them, so without this a link would outlive it
     * until it expires. A controller that cannot ask the account refuses, because it cannot tell.
     *
     * @param string $uid owner of the link
     * @return bool true when the account is there and enabled
     */
    private function ownerUsable(string $uid): bool {
        $user = $this->userManager->get($uid);
        return $user !== null && $user->isEnabled();
    }

    public function spend(CheckoutToken $row, ?string $spent = null): void {
        if (!$this->store->consume($row, $this->time->getTime())) {
            throw new CheckoutFailure(Http::STATUS_GONE, $spent ?? CheckoutMessages::tokenSpent());
        }
    }
}
