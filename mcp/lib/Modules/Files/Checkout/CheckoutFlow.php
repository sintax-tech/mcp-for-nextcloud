<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\OAuth\Storage\TokenHasher;
use OCA\Mcp\Modules\Files\Presentation\CheckoutMessages;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

/**
 * The two routes a checkout hands to an agent: GET downloads the file, PUT/POST uploads the edited one. The
 * PUT/POST route also spends the create link of files_upload, which creates one new file at the path it remembers.
 *
 * Both are public pages without CSRF because the 256-bit token in the path IS the credential: it is
 * single use, expires in minutes and is bound to one user, one file id and one ETag. Nothing about the
 * session is trusted — a logged-in user other than the one the token was issued to gets 404 — and the
 * service switch, the eligibility, the connection and the files.edit grant are re-read on every request,
 * so revoking access takes effect on the next request even for a token issued earlier.
 *
 * The order of the checks is itself the contract: refuse what the headers and the token alone can refuse before a
 * byte of the body reaches the disk, keep the body, re-read the policy, resolve the node, spend the token, re-check
 * the ETag, take the backup and only then write. A create link follows the same order with its own checks.
 *
 * A file locked by files_lock (open in Text or Office, locked by another person or a WebDAV client) is a 423. When the
 * lock is there before the link is spent, the link stays usable; a lock met after that asks for a new checkout.
 */
final class CheckoutFlow {
	/**
	 * @param CheckoutLanguage $language Early owner language.
	 * @param CheckoutPolicy $policy Current policy and consumption.
	 * @param CheckoutDownload $download Download response.
	 * @param CheckoutWriter $writer Upload writes.
	 * @param CheckoutService $checkout Configured limits.
	 * @param BodyReader $body Bounded raw bytes.
	 * @param CheckoutRefusals $refusals Safe responses.
	 * @param CheckoutTokenStore $store Token lookup.
	 * @param TokenHasher $hasher Token digest.
	 */
	public function __construct(
		private CheckoutLanguage $language,
		private CheckoutPolicy $policy,
		private CheckoutDownload $download,
		private CheckoutWriter $writer,
		private CheckoutService $checkout,
		private BodyReader $body,
		private CheckoutRefusals $refusals,
		private CheckoutTokenStore $store,
		private TokenHasher $hasher,
	) {
	}

	/**
	 * Streams the file the checkout was issued for, spending the download token.
	 *
	 * @param \Closure(): ?string $routeToken Route-only token lookup.
	 * @return Response the raw bytes, or 404/410/403 without a body
	 */
	public function download(\Closure $routeToken): Response {
		try {
			$this->language->configureUserL10n($routeToken);
			$found = $this->store->find($this->hasher->hash($routeToken() ?? ''));
			return $this->policy->asOwner($found, fn (): Response => $this->download->send($found));
		} finally {
			Translator::reset();
		}
	}

	/**
	 * Replaces the file with the uploaded bytes, spending the upload token.
	 *
	 * The body is checked before the token is spent, so a call the agent got wrong — a multipart body, an
	 * empty body, a body over the limit — still leaves the link usable. Everything from the guard on leaves
	 * it spent, because from there the write may or may not have happened and a retry would be ambiguous.
	 *
	 * A create link of files_upload takes the same route and the same body checks, except that an empty body is
	 * a valid empty file: it cannot erase anything, because the link never writes over an existing file.
	 *
	 * The token is read from the route only, never as a method argument: Request::decodeContent() merges a
	 * JSON or urlencoded body into the same parameter array (lib/private/AppFramework/Http/Request.php:388-425),
	 * so an argument would let a body choose which file it writes.
	 *
	 * @param \Closure(string): string $readHeader Raw HTTP header lookup.
	 * @param \Closure(): ?string $routeToken Route-only token lookup.
	 * @param \Closure(): (resource|false) $openInput Raw input opener.
	 * @return Response the JSON receipt (201 for a created file), or 409 for a conflict or a taken name, 403 for a
	 *         refused write, and 400/404/410/413/500 for the rest
	 */
	public function upload(\Closure $readHeader, \Closure $routeToken, \Closure $openInput): Response {
		try {
			$this->language->configureUserL10n($routeToken);
			if ($failure = $this->body->checkBodyType($readHeader('Content-Type'))) {
				return $failure;
			}
			$token = $routeToken();
			if ($token === null) {
				return $this->refusals->refuse(Http::STATUS_NOT_FOUND, CheckoutMessages::tokenInvalid());
			}
			// Decided from the prefix because the store is not read before the headers pass; the stored kind still rules.
			$create = CheckoutService::isCreateToken($token);
			$limit = $this->checkout->maxBytes();
			// The route is public: everything that can be refused from the headers and the token is refused before a
			// byte of the body reaches the disk, so a request without a valid link cannot fill the temporary folder.
			if ($failure = $this->body->checkBody($readHeader('Content-Length'), $limit, $create)) {
				return $failure;
			}
			$row = $this->store->find($this->hasher->hash($token));
			if ($failure = $this->policy->checkLink($row, $create ? CheckoutToken::KIND_CREATE : CheckoutToken::KIND_UPLOAD)) {
				return $failure;
			}
			return $this->body->withBody($limit, $openInput,
				fn (string $body): Response => $this->policy->asOwner($row,
					fn (): Response => $create ? $this->writer->create($row, $body) : $this->writer->replace($row, $body)),
				fn (): Response => $this->refusals->refuse(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, CheckoutMessages::uploadTooLarge($limit)));
		} finally {
			Translator::reset();
		}
	}
}
