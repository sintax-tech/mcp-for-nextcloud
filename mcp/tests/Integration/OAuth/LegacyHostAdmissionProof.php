<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Db\{CimdHostsMapper, OAuthClientsMapper, PolicyStateMapper};
use OCA\Mcp\OAuth\Authorization\{AuthorizationRequest, OAuthException};
use OCA\Mcp\OAuth\Clients\{ClientAdmission, ClientMetadata, OAuthClientsService};
use OCA\Mcp\OAuth\Tokens\{AccessTokenAuthenticator, TokenService};
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Upgrade proof using a spent hash actually written by the published baseline, before the candidate is installed. */
final class LegacyHostAdmissionProof {
	/** @param TestCase $test Running test. @param array $legacy Private baseline fixture. @return void Old replay cannot cut a new connection. */
	public static function run(TestCase $test, array $legacy): void {
		$hosts = array_column(array_filter(Server::get(CimdHostsMapper::class)->all(), static fn (array $r): bool => $r['enabled']), 'host');
		$service = Server::get(OAuthClientsService::class);
		$save = static fn (array $next) => $service->update(Server::get(PolicyStateMapper::class)->state()['revision'], $next, null, null);
		$newHosts = [...$hosts, 'mcp-it.example'];
		$save($newHosts);
		try {
			$client = $legacy['untrusted_client'];
			$generation = ClientAdmission::generation(Server::get(OAuthClientsMapper::class), $client);
			$test->assertSame('2', $generation);
			$challenge = rtrim(strtr(base64_encode(hash('sha256', $legacy['verifier'], true)), '+/', '-_'), '=');
			$request = new AuthorizationRequest(new ClientMetadata($client, 'Legacy proof', [$legacy['redirect']], $generation),
				$legacy['redirect'], 'state', $challenge, $legacy['resource'], 'mcp');
			$tokens = Server::get(TokenService::class);
			$code = $tokens->consent($request, $legacy['uid']);
			$new = $tokens->exchangeCode(['code' => $code, 'client_id' => $client, 'redirect_uri' => $legacy['redirect'],
				'code_verifier' => $legacy['verifier'], 'resource' => $legacy['resource']]);
			$bearer = static fn () => Server::get(AccessTokenAuthenticator::class)->authenticate('Bearer ' . $new['access_token'], $legacy['resource'])?->getUID();
			$test->assertSame($legacy['uid'], $bearer());
			try {
				$tokens->refresh(['client_id' => $client, 'refresh_token' => $legacy['untrusted_spent']]);
				$test->fail('A legacy spent token was accepted');
			} catch (OAuthException $e) {
				$test->assertSame('invalid_grant', $e->error);
			}
			$test->assertSame($legacy['uid'], $bearer(), 'Legacy replay must not revoke the new host generation');
			$test->assertFalse($save($newHosts), 'Repeating admission changes nothing');
			$test->assertSame($legacy['uid'], $bearer(), 'Repeated admission must not cut a live connection');
		} finally {
			$save($hosts);
		}
	}
}
