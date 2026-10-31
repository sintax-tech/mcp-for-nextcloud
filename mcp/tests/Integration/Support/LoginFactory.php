<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use OC\Authentication\Token\IProvider;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\TokenService;
use OCP\Authentication\Token\IToken;
use OCP\Security\ISecureRandom;
use OCP\Server;

/** Issues the two kinds of login the MCP endpoint accepts, through the server's and the app's own services. */
final class LoginFactory {
	private const RESOURCE = 'http://localhost/apps/mcp/';

	/** An app password (permanent token), as the personal settings page creates it, used with HTTP Basic. */
	public static function basic(string $uid): Credential {
		$token = Server::get(ISecureRandom::class)->generate(72, ISecureRandom::CHAR_ALPHANUMERIC);
		Server::get(IProvider::class)->generateToken($token, $uid, $uid, null, 'mcp-it exclusion proof', IToken::PERMANENT_TOKEN);
		return Credential::basic($uid, $token);
	}

	/** An access token from the app's own OAuth token service: consent code exchanged with PKCE, as a client does. */
	public static function bearer(string $uid): Credential {
		// The native client is turned on as the admin page does it: one policy command at the current revision.
		if (!\OCA\Mcp\OAuth\ClientResolver::nativeEnabled(Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class))) {
			Server::get(\OCA\Mcp\OAuth\OAuthClientsService::class)->update(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'], null, true, null);
		}
		$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
		$redirect = NativeClient::REDIRECT_URIS[0];
		$service = Server::get(TokenService::class);
		// The consent of 1.1.0 issues the code only through the policy writer, behind the same admission as the page.
		$code = $service->consent(new AuthorizationRequest(NativeClient::metadata((string)\OCA\Mcp\OAuth\ClientResolver::nativeGeneration(Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class))), $redirect, 'it', $challenge, self::RESOURCE, 'mcp'), $uid)
			?? throw new \RuntimeException('The consent of ' . $uid . ' was refused: service off, account disabled or not eligible');
		$body = $service->exchangeCode(['code' => $code, 'code_verifier' => $verifier, 'client_id' => NativeClient::CLIENT_ID,
			'redirect_uri' => $redirect, 'resource' => self::RESOURCE]);
		return Credential::bearer($uid, (string)$body['access_token']);
	}
}
