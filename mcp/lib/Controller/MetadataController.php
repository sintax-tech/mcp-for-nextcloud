<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\ResourceUrl;
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
            'resource_name' => 'Nextcloud MCP',
        ]);
    }

    /** @return JSONResponse authorization server metadata (RFC 8414 fields plus the OIDC-required ones) */
    #[PublicPage]
    #[NoCSRFRequired]
    public function authorizationServer(): JSONResponse {
        return $this->json([
            'issuer' => $this->urls->issuer(),
            'authorization_endpoint' => $this->urls->endpoint('oauth/authorize'),
            'token_endpoint' => $this->urls->endpoint('oauth/token'),
            'jwks_uri' => $this->urls->endpoint('.well-known/jwks.json'),
            'scopes_supported' => AuthorizationValidator::SCOPES,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
            // Required by OpenID Connect Discovery parsers; no ID tokens are issued (the openid scope is not offered).
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]);
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
