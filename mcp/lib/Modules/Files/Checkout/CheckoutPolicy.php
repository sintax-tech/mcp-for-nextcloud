<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Modules\Files\Presentation\CheckoutMessages;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;

/** Admission, scope and atomic use of checkout capabilities. */
final class CheckoutPolicy {
	/** Module required by every checkout operation. */
	private const MODULE = 'files';
	/** Grant for downloads and replacement uploads. */
	public const OPERATION = 'edit';
	/** Grant for the creation capability. */
	public const OPERATION_CREATE = 'create';

	/**
	 * @param CheckoutRefusals $refusals Safe HTTP responses.
	 * @param CheckoutTokenStore $store Atomic token storage.
	 * @param GrantPolicy $policy Current grants.
	 * @param IUserSession $userSession Current session.
	 * @param ITimeFactory $time Expiration clock.
	 * @param ?IUserManager $userManager Owner lookup.
	 * @param ?GuestRestrictions $guests Current Guests restrictions.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard Current Files scope.
	 */
	public function __construct(
		private CheckoutRefusals $refusals,
		private CheckoutTokenStore $store,
		private GrantPolicy $policy,
		private IUserSession $userSession,
		private ITimeFactory $time,
		private ?IUserManager $userManager = null,
		private ?GuestRestrictions $guests = null,
		private ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Runs one use of a link as one policy operation of its owner (GrantPolicy::within): the grant, the Files scope
	 * and every node check of this request read the same snapshot, and the Files gate knows whose scope applies. A
	 * token emitted before a scope change is judged by the scope of now.
	 *
	 * @param CheckoutToken|null $row the row of the presented token; without one nothing is read and no operation opens
	 * @param \Closure(): Response $work the use of the link
	 * @return Response what the use answered, or 503 when the policy cannot be read now
	 */
	public function asOwner(?CheckoutToken $row, \Closure $work): Response {
		if ($row === null) {
			return $work();
		}
		try {
			return $this->policy->within($row->userId, $work);
		} catch (PolicyUnavailable) {
			return $this->refusals->refuse(Http::STATUS_SERVICE_UNAVAILABLE, CheckoutMessages::checkoutUnavailable());
		}
	}

	/**
	 * What makes a link worth reading the body for: it exists, it is of the kind the route expects, the session agrees
	 * with it, and it is neither spent nor expired. Nothing is spent here; consume() still decides the single use.
	 *
	 * @param CheckoutToken|null $row the row of the presented token, null when there is none
	 * @param string $kind CheckoutToken::KIND_UPLOAD or KIND_CREATE, as the prefix of the token announced
	 * @return Response|null the refusal, or null when the body may be read
	 */
	public function checkLink(?CheckoutToken $row, string $kind): ?Response {
		if ($row === null || $row->kind !== $kind) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenWrongKind());
		}
		$session = $this->userSession->getUser();
		if ($session !== null && $session->getUID() !== $row->userId) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
		}
		// The account is asked before the body is read, so a disabled one costs neither a temporary file nor the link.
		if (!$this->ownerUsable($row->userId)) {
			return $this->refusals->refuse(Http::STATUS_FORBIDDEN, CheckoutMessages::checkoutRevoked());
		}
		// A spent or expired link says so before anything else, even when the file of a create link now exists.
		if ($row->used || $row->expiresAt <= $this->time->getTime()) {
			return $this->refusals->refuse(Http::STATUS_GONE, $kind === CheckoutToken::KIND_CREATE ? \OCA\Mcp\Modules\Files\Presentation\CreationMessages::createTokenSpent() : CheckoutMessages::tokenSpent());
		}
		return null;
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

	/**
	 * The policy of the link's owner, read again on every use of an issued link (contracts §4.3): the Guests
	 * restrictions of a guest owner, enabled account, connection allowed and the files grant of this step, resolved
	 * with groups like any MCP request, in one snapshot.
	 *
	 * @param string $uid Link owner.
	 * @param string $operation Grant required by this step.
	 * @return Response|null 404 for a guest Guests refuses, 403 when the policy refuses, 503 when it cannot be resolved
	 *     now; null to proceed
	 */
	public function admit(string $uid, string $operation): ?Response {
		// The link carries no session, so Guests never set up its restrictions for its owner: the app list and the
		// filter of external storage are applied here, before a file is resolved. A guest refused is answered like an
		// unknown link, so the answer tells nothing about the link.
		$owner = $this->userManager?->get($uid);
		if ($owner !== null && $this->guests !== null && !$this->guests->applyToOwner($owner)) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
		}
		try {
			$allowed = $this->ownerUsable($uid) && $this->policy->within($uid,
				fn (): bool => $this->policy->canConnect($uid) && $this->policy->granted($uid, self::MODULE, $operation));
		} catch (PolicyUnavailable) {
			return $this->refusals->refuse(Http::STATUS_SERVICE_UNAVAILABLE, CheckoutMessages::checkoutUnavailable());
		}
		return $allowed ? null : $this->refusals->refuse(Http::STATUS_FORBIDDEN, CheckoutMessages::checkoutRevoked());
	}

	/**
	 * The Files scope of the owner, read once after the grant admitted the step (F3 of the B4 review), so a scope
	 * that cannot be read answers 503 instead of the 404 a later node check would turn it into.
	 *
	 * @return Response|null 503 when it cannot be read; null to proceed
	 */
	public function scopeReadable(): ?Response {
		try {
			$this->visibilityGuard?->assertScopeReadable();
		} catch (PolicyUnavailable) {
			return $this->refusals->refuse(Http::STATUS_SERVICE_UNAVAILABLE, CheckoutMessages::checkoutUnavailable());
		}
		return null;
	}

	/** @param string $uid Link owner. @return bool Whether that account is enabled. */
	private function ownerUsable(string $uid): bool {
		$user = $this->userManager?->get($uid);
		return $user !== null && $user->isEnabled();
	}

	/**
	 * @param CheckoutToken $row the token about to be used
	 * @param string|null $spent the refusal of a spent token, the checkout one when null
	 * @return Response|null 410 when another request spent it first, which is what makes the token single use
	 */
	public function spend(CheckoutToken $row, ?string $spent = null): ?Response {
		return $this->store->consume($row, $this->time->getTime()) ? null : $this->refusals->refuse(Http::STATUS_GONE, $spent ?? CheckoutMessages::tokenSpent());
	}

	/** @return \OCP\IUser|null The session user at this check. */
	public function sessionUser(): ?\OCP\IUser {
		return $this->userSession->getUser();
	}
}
