<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

/** A validated Client ID Metadata Document: the client_id URL, its self-asserted name and its redirect URIs. */
class ClientMetadata {
	/**
	 * @param string $clientId the HTTPS URL that is both the client_id and the document location
	 * @param string $name client_name from the document (self-asserted; show it next to the host)
	 * @param string[] $redirectUris registered redirect URIs
	 * @param string $generation generation of an administrator's client (native or self-hosted) when the request was
	 *     made, a canonical counter; '1' for a CIMD client, which has none
	 */
	public function __construct(
		public readonly string $clientId,
		public readonly string $name,
		public readonly array $redirectUris,
		public readonly string $generation = '1',
	) {}

	/**
	 * Whether a request may return to $redirectUri. CIMD and native clients match per RFC 8252 §7.3 (an http loopback
	 * URI may choose its port, see RedirectUriMatcher); a self-hosted client matches exactly as the administrator
	 * stored it, port included, so registering a port really restricts it.
	 */
	public function allowsRedirect(string $redirectUri): bool {
		return CustomClient::owns($this->clientId)
			? in_array($redirectUri, $this->redirectUris, true)
			: RedirectUriMatcher::matches($redirectUri, $this->redirectUris);
	}

	/**
	 * @return string host of the client_id URL (a fixed label for the native client, the administrator's name for a
	 *     self-hosted one), the identity shown on the consent screen
	 */
	public function host(): string {
		if ($this->clientId === NativeClient::CLIENT_ID) {
			return NativeClient::NAME;
		}
		if (CustomClient::owns($this->clientId)) {
			return $this->name;
		}
		return (string)parse_url($this->clientId, PHP_URL_HOST);
	}
}
