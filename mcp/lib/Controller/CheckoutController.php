<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Modules\Files\Checkout\CheckoutFlow;
use OCA\Mcp\Modules\Files\Checkout\CheckoutPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/** Public single-use checkout routes; request data stays at this HTTP boundary. See {@see CheckoutFlow}. */
class CheckoutController extends Controller {
	/** Compatibility alias for consumers of the public create operation. */
	public const OPERATION_CREATE = CheckoutPolicy::OPERATION_CREATE;

	/**
	 * @param string $appName Application name supplied by the router.
	 * @param IRequest $request Current HTTP request.
	 * @param CheckoutFlow $flow Focused checkout services.
	 */
	public function __construct(string $appName, IRequest $request, private CheckoutFlow $flow) {
		parent::__construct($appName, $request);
	}

	/** @return Response The download or a safe refusal. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(): Response {
		return $this->flow->download(fn (): ?string => $this->routeToken());
	}

	/** @return Response The upload receipt or a safe refusal. */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function upload(): Response {
		return $this->flow->upload(fn (string $name): string => $this->request->getHeader($name),
			fn (): ?string => $this->routeToken(), fn () => $this->inputStream());
	}

	/**
	 * POST alias of upload(): the same raw body, for clients that do not use PUT.
	 *
	 * @return Response the JSON receipt, or a refusal
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadPost(): Response {
		return $this->upload();
	}

	/**
	 * @return string|null the token as the route delivered it, and nothing a body could have injected
	 */
	protected function routeToken(): ?string {
		$token = $this->request->urlParams['token'] ?? null;
		return is_string($token) && $token !== '' ? $token : null;
	}

	/**
	 * IRequest does not expose the raw body of a PUT, so php://input is the only source; it is a seam
	 * so a unit test can stage the bytes.
	 *
	 * @return resource|false the request body
	 */
	protected function inputStream() {
		return @fopen('php://input', 'rb');
	}
}
