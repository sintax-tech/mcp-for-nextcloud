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
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;

/** Checks the ETag, spends the capability and opens the download. */
final class CheckoutDownload {
	/**
	 * @param CheckoutResolver $resolver Visible node resolution.
	 * @param CheckoutPolicy $policy Single-use consumption.
	 * @param CheckoutRefusals $refusals Safe responses.
	 */
	public function __construct(
		private CheckoutResolver $resolver,
		private CheckoutPolicy $policy,
		private CheckoutRefusals $refusals,
	) {
	}

	/**
	 * The download itself, inside the operation of the link's owner.
	 *
	 * @param CheckoutToken|null $found the row of the presented token, null when there is none
	 * @return Response the raw bytes, or a refusal without a body
	 */
	public function send(?CheckoutToken $found): Response {
		$opened = $this->resolver->resolve($found, CheckoutToken::KIND_DOWNLOAD);
		if ($opened instanceof Response) {
			return $opened;
		}
		[$row, $file] = $opened;
		try {
			NodeAccess::checkEtag($file, $row->etag);
		} catch (ToolFailure) {
			return $this->refusals->refuse(Http::STATUS_CONFLICT, CommonMessages::conflict());
		}
		if ($failure = $this->policy->spend($row)) {
			return $failure;
		}
		$handle = $file->fopen('r');
		if ($handle === false) {
			return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
		}
		// filename*=UTF-8'' is the form that survives a name with spaces or accents; the plain filename
		// is the percent-encoded fallback for clients that only understand RFC 6266.
		$name = $file->getName();
		return new StreamResponse($handle, Http::STATUS_OK, [
			'Content-Type' => (string)$file->getMimetype(),
			'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', rawurlencode($name), rawurlencode($name)),
			'Cache-Control' => 'no-store',
		]);
	}
}
