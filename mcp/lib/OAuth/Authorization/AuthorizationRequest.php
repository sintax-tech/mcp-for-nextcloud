<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Authorization;

use OCA\Mcp\OAuth\Clients\ClientMetadata;

/** A validated authorization request, kept in the session between the consent page and the consent POST. */
class AuthorizationRequest {
	/**
	 * @param ClientMetadata $client validated public client
	 * @param string $redirectUri validated callback
	 * @param string $state client correlation value
	 * @param string $codeChallenge PKCE S256 challenge
	 * @param string $resource intended protected resource
	 * @param string $scope accepted scopes
	 * @param string $issuer authorization issuer captured before the consent POST
	 */
	public function __construct(
		public readonly ClientMetadata $client,
		public readonly string $redirectUri,
		public readonly string $state,
		public readonly string $codeChallenge,
		public readonly string $resource,
		public readonly string $scope,
		public readonly string $issuer = '',
	) {}

	/**
	 * @param string|null $issuer issuer of the authorize GET, captured when storing a pending consent
	 * @return array<string,mixed> session-safe representation
	 */
	public function toArray(?string $issuer = null): array {
		return [
			'client_id' => $this->client->clientId,
			'client_name' => $this->client->name,
			'redirect_uris' => $this->client->redirectUris,
			'client_generation' => $this->client->generation,
			'redirect_uri' => $this->redirectUri,
			'state' => $this->state,
			'code_challenge' => $this->codeChallenge,
			'resource' => $this->resource,
			'scope' => $this->scope,
			'issuer' => $issuer ?? $this->issuer,
		];
	}

	/**
	 * @param array<string,mixed> $data output of toArray()
	 * @return self the restored request
	 */
	public static function fromArray(array $data): self {
		return new self(
			new ClientMetadata((string)$data['client_id'], (string)$data['client_name'], (array)$data['redirect_uris'],
				is_string($data['client_generation'] ?? null) ? $data['client_generation'] : ''),
			(string)$data['redirect_uri'],
			(string)$data['state'],
			(string)$data['code_challenge'],
			(string)$data['resource'],
			(string)$data['scope'],
			is_string($data['issuer'] ?? null) ? $data['issuer'] : '',
		);
	}

	/**
	 * Restores only a pending consent with its original issuer; older pages must restart discovery.
	 *
	 * @param mixed $stored session entry containing pending requests
	 * @param string $pending submitted pending request identifier
	 * @return self|null a request bound to the authorize GET, or null for an expired/legacy entry
	 */
	public static function fromPending(mixed $stored, string $pending): ?self {
		if (!is_array($stored) || !is_array($stored[$pending] ?? null)
			|| !is_string($stored[$pending]['issuer'] ?? null) || $stored[$pending]['issuer'] === '') {
			return null;
		}
		return self::fromArray($stored[$pending]);
	}

	/**
	 * @param array<string,string> $params code or error parameters
	 * @param string|null $issuer explicit issuer for non-pending requests; null uses the captured issuer (RFC 9207)
	 * @return string redirect_uri with the parameters, `iss` and the original state appended
	 */
	public function redirectWith(array $params, ?string $issuer = null): string {
		return self::buildRedirect($this->redirectUri, $params, $this->state, $issuer ?? $this->issuer);
	}

	/**
	 * The single place that builds a redirect back to a client: appends `iss` exactly once, then `state` if any.
	 *
	 * @param string $redirectUri verified redirect_uri (may already carry a query)
	 * @param array<string,string> $params code or error parameters
	 * @param string $state client state, preserved when not empty
	 * @param string $issuer authorization server issuer
	 * @return string the redirect URL
	 */
	public static function buildRedirect(string $redirectUri, array $params, string $state, string $issuer): string {
		$params['iss'] = $issuer;
		if ($state !== '') {
			$params['state'] = $state;
		}
		return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params);
	}
}
