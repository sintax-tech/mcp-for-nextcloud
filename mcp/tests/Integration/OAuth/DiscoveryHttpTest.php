<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Tests\Integration\Support\Http;

/** Exercises public discovery through Apache, including the OIDC fallback required by MCP clients. */
final class DiscoveryHttpTest extends OAuthAccountTestCase {
	/** @return void the challenge identifies the resource and both app metadata routes agree on its issuer */
	public function testChallengeLeadsToIssuerMetadataWithoutClientRegistration(): void {
		$url = Http::BASE . '/index.php/apps/mcp/';
		$response = Http::request('POST', $url, [
			'Content-Type: application/json', 'Accept: application/json, text/event-stream',
		], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
		$this->assertSame(401, $response['status']);
		$this->assertSame(1, preg_match('/resource_metadata="([^"]+)"/', $response['headers'], $match));
		$resource = Http::request('GET', $match[1]);
		$this->assertSame(200, $resource['status']);
		$resourceData = json_decode($resource['body'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame($url, $resourceData['resource']);
		$this->assertSame([rtrim($url, '/')], $resourceData['authorization_servers']);
		$this->assertSame(['mcp'], $resourceData['scopes_supported']);
		$documents = [];
		foreach (['openid-configuration', 'oauth-authorization-server'] as $suffix) {
			$metadata = Http::request('GET', $url . '.well-known/' . $suffix);
			$this->assertSame(200, $metadata['status']);
			$data = json_decode($metadata['body'], true, 512, JSON_THROW_ON_ERROR);
			$this->assertSame($resourceData['authorization_servers'][0], $data['issuer']);
			$this->assertTrue($data['authorization_response_iss_parameter_supported']);
			$this->assertSame($url . 'oauth/authorize', $data['authorization_endpoint']);
			$this->assertSame($url . 'oauth/token', $data['token_endpoint']);
			$this->assertSame(['S256'], $data['code_challenge_methods_supported']);
			$this->assertSame(['none'], $data['token_endpoint_auth_methods_supported']);
			$this->assertTrue($data['client_id_metadata_document_supported']);
			$this->assertArrayNotHasKey('registration_endpoint', $data);
			$documents[] = $data;
		}
		$this->assertSame($documents[0], $documents[1]);
	}
}
