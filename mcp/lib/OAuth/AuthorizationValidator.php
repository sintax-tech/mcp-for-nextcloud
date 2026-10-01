<?php
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

/**
 * Validates an authorization request (OAuth 2.1 + PKCE S256 + RFC 8707 resource). Client and redirect_uri are
 * checked first and fail without redirect; later errors are redirectable to the verified redirect_uri.
 */
class AuthorizationValidator {
    /** Scopes accepted; grants are still decided by the admin matrix, not by scopes. */
    public const SCOPES = ['mcp', 'offline_access'];

    public function __construct(private ClientMetadataFetcher $fetcher) {}

    /**
     * @param array<string,mixed> $params query parameters of the authorization request
     * @param string $expectedResource this server's resource URL
     * @return AuthorizationRequest the validated request
     * @throws OAuthException on any invalid parameter
     */
    public function validate(array $params, string $expectedResource): AuthorizationRequest {
        $get = static fn (string $name): string => is_string($params[$name] ?? null) ? $params[$name] : '';
        $client = $this->fetcher->fetch($get('client_id'));
        $redirectUri = $get('redirect_uri');
        if ($redirectUri === '' || !RedirectUriMatcher::matches($redirectUri, $client->redirectUris)) {
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
            throw new OAuthException('invalid_scope', 'MCP scope is required; unknown scopes are not supported', true);
        }
        return new AuthorizationRequest($client, $redirectUri, $get('state'), $get('code_challenge'),
            $resource !== '' ? $resource : $expectedResource, $scopes === [] ? 'mcp' : implode(' ', $scopes));
    }
}
