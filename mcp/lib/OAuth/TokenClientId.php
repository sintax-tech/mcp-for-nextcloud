<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\IRequest;

/**
 * Identification of a public client at the token endpoint by HTTP Basic with an empty password (issue #19).
 *
 * Some clients configured with a client_id and an empty secret send `Authorization: Basic base64(client_id:)` and no
 * client_id parameter. This is an interoperability extension for public clients only: nothing is authenticated, no
 * secret is ever accepted or checked, and the metadata keeps announcing only `none`. TokenService still binds the code
 * or the refresh token to the exact client_id, redirect URI, PKCE verifier, resource and generations.
 *
 * Whenever the request parameters (IRequest::getParams(), which merges query, body and route parameters) contain a
 * client_id key, whatever its type or value, they are returned unchanged and the header is not even read. Only when the
 * key is absent is the raw Authorization header that IRequest delivers parsed, strictly: no fallback to PHP_AUTH_USER /
 * PHP_AUTH_PW or REDIRECT_HTTP_AUTHORIZATION, so a server or proxy that does not pass the header keeps today's answer.
 */
final class TokenClientId {
	/** Anchored at both ends (\A, \z): in PCRE `$` would also match before a final newline. */
	private const BASIC = '/\ABasic +([^\s]+)\z/i';

	/** A `%` not followed by two hexadecimal digits makes a form-urlencoded component malformed. */
	private const BAD_ESCAPE = '/%(?![0-9A-Fa-f]{2})/';

	/**
	 * The token request parameters with the client_id of an empty-password Basic header when they have none.
	 *
	 * @param array<string,mixed> $params the parameters of the token request, as IRequest::getParams() returns them
	 * @param IRequest $request the request, read only for its Authorization header and only when client_id is absent
	 * @return array<string,mixed> $params unchanged, or $params with client_id added from the header
	 */
	public static function resolve(array $params, IRequest $request): array {
		if (array_key_exists('client_id', $params)) {
			return $params;
		}
		$clientId = self::fromBasic($request->getHeader('Authorization'));
		if ($clientId !== null) {
			$params['client_id'] = $clientId;
		}
		return $params;
	}

	/**
	 * The client_id of a strict Basic header with an empty password.
	 *
	 * @param string $header the raw Authorization header
	 * @return string|null the decoded user part, or null when the header is absent, not Basic, not strict base64,
	 *     has no or more than one raw colon, a malformed escape, a password other than exactly '' or an empty user
	 */
	private static function fromBasic(string $header): ?string {
		if (preg_match(self::BASIC, $header, $matches) !== 1) {
			return null;
		}
		$decoded = base64_decode($matches[1], true);
		if (!is_string($decoded) || substr_count($decoded, ':') !== 1) {
			return null;
		}
		[$user, $password] = explode(':', $decoded, 2);
		$user = self::formDecode($user);
		$password = self::formDecode($password);
		if ($user === null || $user === '' || $password !== '') {
			return null;
		}
		return $user;
	}

	/**
	 * Decodes one application/x-www-form-urlencoded component exactly once.
	 *
	 * @param string $value the raw component, split from the other one before any decoding
	 * @return string|null the decoded value, or null for a `%` not followed by two hexadecimal digits
	 */
	private static function formDecode(string $value): ?string {
		if (preg_match(self::BAD_ESCAPE, $value) === 1) {
			return null;
		}
		return urldecode($value);
	}
}
