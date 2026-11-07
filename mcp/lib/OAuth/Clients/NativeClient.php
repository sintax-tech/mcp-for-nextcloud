<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Clients;

use OCA\Mcp\OAuth\Authorization\RedirectUriMatcher;

/**
 * The optional built-in public client for local programs (for example Gemini CLI) that cannot publish a Client
 * ID Metadata Document. It is off until an administrator enables {@see self::CONFIG_KEY}. It has a fixed,
 * non-secret client_id, uses PKCE and may only return to a loopback callback.
 */
final class NativeClient {
	public const CLIENT_ID = 'nextcloud-mcp-native';
	/** The 1.0.8 switch in the app configuration; only the upgrade migration still reads it, to import it. */
	public const CONFIG_KEY = 'oauth_native_client_enabled';
	public const NAME = 'Local program (native client)';
	/** Loopback callbacks; the port is free per RFC 8252 7.3 (see RedirectUriMatcher). */
	public const REDIRECT_URIS = [
		'http://localhost/oauth/callback',
		'http://127.0.0.1/oauth/callback',
		'http://[::1]/oauth/callback',
	];

	/**
	 * @param string $generation the client's generation, which advances each time the administrator turns it off
	 * @return ClientMetadata metadata of the native client
	 */
	public static function metadata(string $generation = '1'): ClientMetadata {
		return new ClientMetadata(self::CLIENT_ID, self::NAME, self::REDIRECT_URIS, $generation);
	}
}
