<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Authorization;

use OCA\Mcp\OAuth\Clients\ClientResolver;
use OCA\Mcp\OAuth\Clients\CustomClient;
use OCA\Mcp\OAuth\Clients\NativeClient;
use OCA\Mcp\OAuth\Tokens\ResourceUrl;

/**
 * Validates an authorization request (OAuth 2.1 + PKCE S256 + RFC 8707 resource). Client and redirect_uri are
 * checked first and fail without redirect; later errors are redirectable to the verified redirect_uri.
 */
class AuthorizationValidator {
	/** Scopes accepted; grants are still decided by the admin matrix, not by scopes. */
	public const SCOPES = ['mcp', 'offline_access'];

	public function __construct(private ClientResolver $resolver) {}

	/**
	 * @param array<string,mixed> $params query parameters of the authorization request
	 * @param string $expectedResource this server's resource URL
	 * @return AuthorizationRequest the validated request
	 * @throws OAuthException on any invalid parameter
	 */
	public function validate(array $params, string $expectedResource): AuthorizationRequest {
		$get = static fn (string $name): string => is_string($params[$name] ?? null) ? $params[$name] : '';
		$client = $this->resolver->resolve($get('client_id'));
		$redirectUri = $get('redirect_uri');
		if ($redirectUri === '' || !$client->allowsRedirect($redirectUri)) {
			throw new OAuthException('invalid_request', 'redirect_uri is not registered for this client');
		}
		if ($get('response_type') !== 'code') {
			throw new OAuthException('unsupported_response_type', 'Only the code response type is supported', true);
		}
		if ($get('code_challenge_method') !== 'S256' || !Pkce::validChallenge($get('code_challenge'))) {
			throw new OAuthException('invalid_request', 'PKCE with S256 is required', true);
		}
		$resource = $get('resource');
		if ($resource !== '' && !ResourceUrl::sameResource($resource, $expectedResource)) {
			throw new OAuthException('invalid_target', 'Unknown resource', true);
		}
		$scopes = array_values(array_filter(explode(' ', $get('scope'))));
		if (array_diff($scopes, self::SCOPES) !== [] || ($scopes !== [] && !in_array('mcp', $scopes, true))) {
			throw new OAuthException('invalid_scope', 'Unknown scope', true);
		}
		return new AuthorizationRequest($client, $redirectUri, $get('state'), $get('code_challenge'),
			$resource !== '' ? $resource : $expectedResource, $scopes === [] ? 'mcp' : implode(' ', $scopes));
	}

	/**
	 * Checks a request kept in the session against what the administrator allows now, before the consent page answers
	 * anything (allow or deny): the pending request is older than the decision and must not return to a client that was
	 * turned off, deleted or turned on again since, nor to a redirect URI that was withdrawn. CIMD host trust/generation is checked locally too; no document is fetched again.
	 *
	 * @param AuthorizationRequest $request the pending request restored from the session
	 * @throws OAuthException invalid_client / invalid_request (never redirectable) when the request no longer stands
	 */
	public function revalidate(AuthorizationRequest $request): void {
		$id = $request->client->clientId;
		if ($id !== NativeClient::CLIENT_ID && !CustomClient::owns($id)) {
			$this->resolver->revalidateCimd($request->client);
			return;
		}
		$client = $this->resolver->resolve($id);
		if ($client->generation !== $request->client->generation || !$client->allowsRedirect($request->redirectUri)) {
			throw new OAuthException('invalid_request', 'The authorization request is no longer valid for this client');
		}
	}
}
