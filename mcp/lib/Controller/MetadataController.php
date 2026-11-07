<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\OAuth\Authorization\ServerMetadata;
use OCA\Mcp\OAuth\Tokens\ResourceUrl;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * OAuth discovery documents served under /apps/mcp, so no web server or DNS change is needed:
 * protected resource metadata (RFC 9728, pointed to by the 401 challenge) and authorization server metadata at
 * <issuer>/.well-known/openid-configuration (the path-appended discovery URL MCP clients try) and
 * <issuer>/.well-known/oauth-authorization-server.
 */
class MetadataController extends Controller {
	public function __construct(string $appName, IRequest $request, private ResourceUrl $urls) {
		parent::__construct($appName, $request);
	}

	/** @return JSONResponse the protected resource metadata of this MCP server */
	#[PublicPage]
	#[NoCSRFRequired]
	public function protectedResource(): JSONResponse {
		return $this->json([
			'resource' => $this->urls->base(),
			'authorization_servers' => [$this->urls->issuer()],
			'scopes_supported' => ['mcp'],
			'bearer_methods_supported' => ['header'],
			'resource_name' => 'MCP for Cloud',
		]);
	}

	/** @return JSONResponse authorization server metadata (RFC 8414 fields plus the OIDC-required ones) */
	#[PublicPage]
	#[NoCSRFRequired]
	public function authorizationServer(): JSONResponse {
		return $this->json((new ServerMetadata($this->urls))->document());
	}

	/** @return JSONResponse same document as authorizationServer(), for clients probing the RFC 8414 name */
	#[PublicPage]
	#[NoCSRFRequired]
	public function oauthServer(): JSONResponse {
		return $this->authorizationServer();
	}

	/** @return JSONResponse an empty key set: tokens are opaque, nothing is signed */
	#[PublicPage]
	#[NoCSRFRequired]
	public function jwks(): JSONResponse {
		return $this->json(['keys' => []]);
	}

	/** @param array<string,mixed> $data */
	private function json(array $data): JSONResponse {
		$response = new JSONResponse($data);
		$response->addHeader('Access-Control-Allow-Origin', '*');
		$response->cacheFor(300);
		return $response;
	}
}
