<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Authorization;

use OCA\Mcp\OAuth\Tokens\ResourceUrl;

/** Publishes the same authorization capabilities through OAuth and OIDC discovery. */
final class ServerMetadata {
	/** @param ResourceUrl $urls issuer and endpoints of this installation */
	public function __construct(private ResourceUrl $urls) {}

	/** @return array<string,mixed> public metadata, including the RFC 9207 issuer response capability */
	public function document(): array {
		return [
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
			'authorization_response_iss_parameter_supported' => true,
			// Discovery compatibility only: the openid scope and ID tokens are not offered.
			'subject_types_supported' => ['public'],
			'id_token_signing_alg_values_supported' => ['RS256'],
		];
	}
}
