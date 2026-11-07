<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\Authorization\ServerMetadata;
use OCA\Mcp\OAuth\Authorization\AuthorizationRequest;
use OCA\Mcp\OAuth\Tokens\ResourceUrl;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Verifies discovery identity and RFC 9207 advertisement with every supported URL prefix. */
final class DiscoveryMetadataTest extends TestCase {
	/** @return iterable<string,array{string}> supported installation and front-controller prefixes */
	public static function prefixes(): iterable {
		yield 'root' => [''];
		yield 'front controller' => ['/index.php'];
		yield 'subdirectory' => ['/nextcloud'];
		yield 'subdirectory with front controller' => ['/nextcloud/index.php'];
	}

	/** @param string $prefix installation and front-controller path */
	#[DataProvider('prefixes')]
	public function testDiscoveryAdvertisesTheIssuerActuallyReturnedInCallbacks(string $prefix): void {
		$issuer = 'https://cloud.example.org' . $prefix . '/apps/mcp';
		$documents = [];
		foreach (['openid-configuration', 'oauth-authorization-server'] as $suffix) {
			$request = $this->createMock(IRequest::class);
			$request->method('getRequestUri')->willReturn($prefix . '/apps/mcp/.well-known/' . $suffix);
			$request->method('getServerProtocol')->willReturn('https');
			$request->method('getServerHost')->willReturn('cloud.example.org');
			$data = (new ServerMetadata(new ResourceUrl($request)))->document();
			$this->assertSame($issuer, $data['issuer']);
			$this->assertTrue($data['authorization_response_iss_parameter_supported']);
			$this->assertSame($issuer . '/oauth/authorize', $data['authorization_endpoint']);
			$this->assertSame($issuer . '/oauth/token', $data['token_endpoint']);
			$this->assertSame(['S256'], $data['code_challenge_methods_supported']);
			$this->assertSame(['none'], $data['token_endpoint_auth_methods_supported']);
			$this->assertTrue($data['client_id_metadata_document_supported']);
			$this->assertArrayNotHasKey('registration_endpoint', $data);
			foreach ([['code' => 'test-code'], ['error' => 'access_denied']] as $parameters) {
				$callback = AuthorizationRequest::buildRedirect('https://client.example/callback', $parameters, 'state', $issuer);
				parse_str((string)parse_url($callback, PHP_URL_QUERY), $query);
				$this->assertSame($data['issuer'], $query['iss']);
				$this->assertSame('state', $query['state']);
			}
			$documents[] = $data;
		}
		$this->assertSame($documents[0], $documents[1]);
	}
}
