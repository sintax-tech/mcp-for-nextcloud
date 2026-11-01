<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\OAuthClientsService;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\Server;

/** Issue #13: real rendered consent, session cookies, CSRF and an administrator's external callback. */
final class ConsentHttpTest extends OAuthAccountTestCase {
	private \CurlHandle $browser;

	public function testRemoteAppPasswordRequiresBasicAndDiscoveryIsPublic(): void {
		Server::get(GrantPolicy::class)->setConnected($this->uid, true);
		$url = Http::BASE . '/index.php/apps/mcp/';
		$basic = LoginFactory::basic($this->uid)->authorization();
		$headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
		$message = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR);
		$this->assertSame(200, Http::request('POST', $url, [...$headers, 'Authorization: ' . $basic], $message)['status']);
		[, $password] = explode(':', base64_decode(substr($basic, 6)), 2);
		$bearer = Http::request('POST', $url, [...$headers, 'Authorization: Bearer ' . $password], $message);
		$this->assertSame(200, $bearer['status'], 'Nextcloud also authenticates its own app passwords as Bearer');
		$initialize = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => [
			'protocolVersion' => '2025-06-18', 'capabilities' => (object)[],
			'clientInfo' => ['name' => 'Mistral compatibility proof', 'version' => '1'],
		]], JSON_THROW_ON_ERROR);
		foreach ([$basic, 'Bearer ' . $password] as $credential) {
			$handshake = Http::request('POST', $url, [...$headers, 'Authorization: ' . $credential], $initialize);
			$this->assertSame(200, $handshake['status']);
			$this->assertArrayHasKey('result', json_decode($handshake['body'], true));
		}
		$anonymous = Http::request('POST', $url, $headers, $message);
		$this->assertSame(401, $anonymous['status']);
		$this->assertStringContainsString('resource_metadata=', $anonymous['headers']);
		$this->assertSame(403, Http::request('POST', $url,
			[...$headers, 'Authorization: ' . $basic, 'Origin: https://chat.mistral.ai'], $message)['status']);
		$this->assertSame(406, Http::request('POST', $url,
			['Content-Type: application/json', 'Accept: application/json', 'Authorization: ' . $basic], $message)['status']);
		$metadata = Http::request('GET', $url . '.well-known/oauth-protected-resource');
		$this->assertSame(200, $metadata['status']);
		$this->assertSame($url, json_decode($metadata['body'], true)['resource']);
		$server = Http::request('GET', $url . '.well-known/oauth-authorization-server');
		$this->assertSame(200, $server['status']);
		$this->assertArrayNotHasKey('registration_endpoint', json_decode($server['body'], true));
	}

	/** A cookie session, including the real login and CSRF checks; no OCS-APIRequest bypass. */
	private function browse(string $method, string $url, ?array $form = null): array {
		curl_setopt_array($this->browser, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_POSTFIELDS => $form === null ? null : http_build_query($form),
			CURLOPT_HTTPHEADER => $form === null ? ['Accept: text/html'] : ['Accept: text/html', 'Origin: http://localhost', 'Content-Type: application/x-www-form-urlencoded']]);
		$raw = curl_exec($this->browser);
		$this->assertIsString($raw);
		$size = curl_getinfo($this->browser, CURLINFO_HEADER_SIZE);
		return ['status' => curl_getinfo($this->browser, CURLINFO_RESPONSE_CODE),
			'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
	}

	private function field(string $html, string $name): string {
		$dom = new \DOMDocument();
		@$dom->loadHTML($html);
		$value = (new \DOMXPath($dom))->evaluate('string(//input[@name="' . $name . '"]/@value)');
		$this->assertNotSame('', $value, $name . ' must be rendered');
		return $value;
	}

	public function testConsentReturnsToTheExternalClientAndRejectsReplay(): void {
		$this->browser = curl_init();
		curl_setopt_array($this->browser, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEFILE => '']);
		$password = bin2hex(random_bytes(24));
		$this->user->setPassword($password);
		$login = $this->browse('GET', Http::BASE . '/index.php/login');
		// Nextcloud's login form is rendered by Vue; the shared request token is on the head element.
		preg_match('/data-requesttoken="([^"]+)"/', $login['body'], $token);
		$login = $this->browse('POST', Http::BASE . '/index.php/login', ['user' => $this->uid,
			'password' => $password, 'requesttoken' => html_entity_decode($token[1], ENT_QUOTES)]);
		$this->assertSame(303, $login['status'], $login['body']);
		preg_match('/^Location: (.+)$/mi', $login['headers'], $loginLocation);
		$this->assertStringNotContainsString('/login', $loginLocation[1]);
		$mapper = Server::get(OAuthClientsMapper::class);
		$saved = array_map(static fn (array $c): array => array_diff_key($c, ['gen' => 1]), CustomClient::clients($mapper));
		$service = Server::get(OAuthClientsService::class);
		$save = static function (array $clients) use ($service): void {
			$service->update(Server::get(PolicyStateMapper::class)->state()['revision'], null, null, $clients);
		};
		$redirect = 'https://chat.mistral.ai/oauth/callback';
		$save([...$saved, ['name' => 'Mistral HTTP proof', 'enabled' => true, 'redirectUris' => [$redirect]]]);
		try {
			$client = array_values(array_filter(CustomClient::clients($mapper), static fn (array $c): bool => $c['name'] === 'Mistral HTTP proof'))[0];
			$url = Http::BASE . '/index.php/apps/mcp/oauth/authorize';
			$page = $this->browse('GET', $url . '?' . http_build_query([
				'client_id' => CustomClient::clientId($client['id']), 'redirect_uri' => $redirect,
				'response_type' => 'code', 'code_challenge_method' => 'S256', 'code_challenge' => self::CHALLENGE,
				'state' => 'mistral-proof', 'scope' => 'mcp',
			]));
			$this->assertSame(200, $page['status']);
			$fields = [];
			foreach (['pending', 'requesttoken'] as $name) {
				$fields[$name] = $this->field($page['body'], $name);
			}
			$post = $fields + ['decision' => 'allow'];
			$invalidCsrf = $this->browse('POST', $url, array_replace($post, ['requesttoken' => 'invalid']));
			$this->assertSame(412, $invalidCsrf['status'], 'the consent still requires a valid CSRF token');
			$response = $this->browse('POST', $url, $post);
			$this->assertSame(200, $response['status'], $response['body']);
			$dom = new \DOMDocument();
			@$dom->loadHTML($response['body']);
			$xpath = new \DOMXPath($dom);
			$target = $xpath->evaluate('string(//a/@href)');
			$this->assertSame('0;url=' . $target, $xpath->evaluate('string(//meta[@http-equiv="refresh"]/@content)'));
			$this->assertStringStartsWith($redirect . '?', $target);
			parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
			$this->assertSame('mistral-proof', $query['state']);
			$this->assertNotEmpty($query['code']);
			$this->assertSame('http://localhost/index.php/apps/mcp', $query['iss']);
			$this->assertStringContainsString('Cache-Control: no-store', $response['headers']);
			$this->assertStringContainsString('Referrer-Policy: no-referrer', $response['headers']);
			$this->assertStringNotContainsString('https://chat.mistral.ai', $page['headers']);
			$token = Http::request('POST', Http::BASE . '/index.php/apps/mcp/oauth/token',
				['Content-Type: application/x-www-form-urlencoded'], http_build_query([
					'grant_type' => 'authorization_code', 'code' => $query['code'], 'code_verifier' => self::VERIFIER,
					'client_id' => CustomClient::clientId($client['id']), 'redirect_uri' => $redirect,
				]));
			$this->assertSame(200, $token['status'], $token['body']);
			$this->assertNotEmpty(json_decode($token['body'], true)['access_token']);
			$replay = $this->browse('POST', $url, $post);
			$this->assertStringContainsString('The authorization request expired.', $replay['body']);
		} finally {
			$save($saved);
		}
	}
}
