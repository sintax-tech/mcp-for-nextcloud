<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\Authorization\AuthorizationValidator;
use OCA\Mcp\OAuth\Clients\ClientMetadata;
use OCA\Mcp\OAuth\Clients\ClientMetadataFetcher;
use OCA\Mcp\OAuth\Clients\ClientResolver;
use OCA\Mcp\OAuth\Clients\CustomClient;
use OCA\Mcp\OAuth\Clients\NativeClient;
use OCA\Mcp\OAuth\Authorization\OAuthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F4 of the review of PR #8: the redirect URI of a self-hosted client is matched exactly as the administrator stored it,
 * the port of an http loopback URI included; the ephemeral-port exception of RFC 8252 §7.3 stays for the native client
 * and for CIMD clients, which the README says for them and nothing else.
 */
final class LoopbackPortPolicyTest extends TestCase {
	private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
	private const RESOURCE = 'https://cloud.example.org/apps/mcp/';

	/** @return array<string, array{string, string}> the URI the administrator stores, the same one with another port */
	public static function loopbackProvider(): array {
		return [
			'IPv4' => ['http://127.0.0.1:8080/cb', 'http://127.0.0.1:9999/cb'],
			'localhost' => ['http://localhost:8080/cb', 'http://localhost:9999/cb'],
			'IPv6' => ['http://[::1]:8080/cb', 'http://[::1]:9999/cb'],
			'stored without a port, asked with one' => ['http://127.0.0.1/cb', 'http://127.0.0.1:9999/cb'],
			'stored with a port, asked without' => ['http://127.0.0.1:8080/cb', 'http://127.0.0.1/cb'],
		];
	}

	private function validate(ClientResolver $resolver, string $clientId, string $redirect): void {
		(new AuthorizationValidator($resolver))->validate(['client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
			'code_challenge_method' => 'S256', 'code_challenge' => self::CHALLENGE, 'scope' => 'mcp'], self::RESOURCE);
	}

	#[DataProvider('loopbackProvider')]
	public function testASelfHostedClientMatchesTheStoredLoopbackUriExactlyPortIncluded(string $stored, string $otherPort): void {
		$clients = new ClientsTable($this);
		$clients->custom([['id' => 'aaaaaaaaaaaa', 'name' => 'Local', 'enabled' => true, 'redirectUris' => [$stored]]]);
		$resolver = new ClientResolver($this->createMock(ClientMetadataFetcher::class), $clients->mapper);
		$id = CustomClient::clientId('aaaaaaaaaaaa');
		$this->validate($resolver, $id, $stored);
		try {
			$this->validate($resolver, $id, $otherPort);
			$this->fail('A port the administrator did not register was accepted');
		} catch (OAuthException $e) {
			$this->assertSame('invalid_request', $e->error);
			$this->assertFalse($e->redirectable);
		}
	}

	public function testTheNativeClientKeepsTheEphemeralPortAndNothingElse(): void {
		$clients = new ClientsTable($this);
		$clients->native(true);
		$resolver = new ClientResolver($this->createMock(ClientMetadataFetcher::class), $clients->mapper);
		foreach (['http://localhost:53421/oauth/callback', 'http://127.0.0.1:1/oauth/callback', 'http://[::1]:65535/oauth/callback', 'http://localhost/oauth/callback'] as $uri) {
			$this->validate($resolver, NativeClient::CLIENT_ID, $uri);
			$this->addToAssertionCount(1);
		}
		foreach (['http://localhost:53421/other', 'https://localhost:53421/oauth/callback', 'http://127.0.0.2:53421/oauth/callback'] as $uri) {
			try {
				$this->validate($resolver, NativeClient::CLIENT_ID, $uri);
				$this->fail($uri . ' accepted');
			} catch (OAuthException $e) {
				$this->assertSame('invalid_request', $e->error);
			}
		}
	}

	public function testACimdClientKeepsTheEphemeralPortOfItsLoopbackUri(): void {
		$client = new ClientMetadata('https://app.example.org/client.json', 'App', ['http://127.0.0.1:8080/cb', 'https://app.example.org/cb']);
		$this->assertTrue($client->allowsRedirect('http://127.0.0.1:9999/cb'));
		$this->assertTrue($client->allowsRedirect('https://app.example.org/cb'));
		$this->assertFalse($client->allowsRedirect('https://app.example.org:8443/cb'));
		$this->assertFalse($client->allowsRedirect('http://127.0.0.1:9999/other'));
	}

	public function testAStoredHttpsUriIsStillMatchedByteForByte(): void {
		$client = new ClientMetadata(CustomClient::clientId('aaaaaaaaaaaa'), 'Local', ['https://harness.example.com/oauth/callback?x=1']);
		$this->assertTrue($client->allowsRedirect('https://harness.example.com/oauth/callback?x=1'));
		foreach (['https://harness.example.com/oauth/callback', 'https://harness.example.com/oauth/callback?x=1&y=2',
			'https://harness.example.com:443/oauth/callback?x=1', 'https://HARNESS.example.com/oauth/callback?x=1'] as $uri) {
			$this->assertFalse($client->allowsRedirect($uri), $uri);
		}
	}
}
