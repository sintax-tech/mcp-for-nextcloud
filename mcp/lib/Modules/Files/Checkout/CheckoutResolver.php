<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Modules\Files\Presentation\CheckoutMessages;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;

/** Resolves the same visible node after current policy admission. */
final class CheckoutResolver {
	/**
	 * @param CheckoutPolicy $policy Current capability admission.
	 * @param CheckoutLanguage $language Owner language.
	 * @param CheckoutRefusals $refusals Safe responses.
	 * @param IRootFolder $rootFolder Official node lookup.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard Current scope.
	 */
	public function __construct(
		private CheckoutPolicy $policy,
		private CheckoutLanguage $language,
		private CheckoutRefusals $refusals,
		private IRootFolder $rootFolder,
		private ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
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
	public function resolve(?CheckoutToken $row, string $kind): array|Response {
		if ($row === null || $row->kind !== $kind) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, $kind === CheckoutToken::KIND_UPLOAD ? CheckoutMessages::tokenWrongKind() : CheckoutMessages::tokenInvalid());
		}
		// A link is the credential, so the session may only ever agree with it.
		$session = $this->policy->sessionUser();
		if ($session !== null && $session->getUID() !== $row->userId) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
		}
		$uid = $row->userId;
		if (($refusal = $this->policy->admit($uid, CheckoutPolicy::OPERATION) ?? $this->policy->scopeReadable()) !== null) {
			return $refusal;
		}
		$this->language->forOwner($uid);
		try {
			$file = NodeAccess::requireFile(NodeAccess::get($this->rootFolder->getUserFolder($uid), $row->path, $this->visibilityGuard));
		} catch (\Throwable) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CommonMessages::notFound());
		}
		if ((int)$file->getId() !== $row->fileId) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
		}
		if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($file)) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CommonMessages::notFound());
		}
		return [$row, $file];
	}
}
