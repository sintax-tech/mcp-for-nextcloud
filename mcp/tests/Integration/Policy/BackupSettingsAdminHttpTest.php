<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\IAppConfig;
use OCP\Server;

/** Real framework admin, CSRF and revision admission for the canonical backup document. */
final class BackupSettingsAdminHttpTest extends IntegrationTestCase {
	/**
	 * @param string $uid Fixture account.
	 * @return list<string> Official app-password credentials, session cookies and a CSRF token.
	 */
	private function session(string $uid): array {
		if ($uid === 'admin') {
			return $this->adminSession();
		}
		$auth = 'Authorization: ' . LoginFactory::basic($uid)->authorization();
		$response = Http::request('GET', Http::BASE . '/index.php/csrftoken', [$auth, 'Accept: application/json']);
		self::assertSame(200, $response['status']);
		preg_match_all('/^Set-Cookie:\s*([^=;\s]+=[^;\r\n]*)/mi', $response['headers'], $cookies);
		return [$auth, 'Cookie: ' . implode('; ', $cookies[1]), 'requesttoken: ' . json_decode($response['body'], true)['token']];
	}

	/** @return list<string> A real web login and its current CSRF token, without Basic or OCS bypass. */
	private function adminSession(): array {
		$browser = curl_init();
		curl_setopt_array($browser, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEFILE => '']);
		try {
			$login = $this->browse($browser, 'GET', '/index.php/login');
			self::assertSame(200, $login['status']);
			self::assertSame(1, preg_match('/data-requesttoken="([^"]+)"/', $login['body'], $token));
			$login = $this->browse($browser, 'POST', '/index.php/login', ['user' => 'admin',
				'password' => getenv('NEXTCLOUD_ADMIN_PASSWORD'), 'requesttoken' => html_entity_decode($token[1], ENT_QUOTES)]);
			self::assertSame(303, $login['status']);
			self::assertSame(1, preg_match('/^Location: (.+)$/mi', $login['headers'], $location));
			self::assertStringNotContainsString('/login', $location[1]);
			$current = $this->browse($browser, 'GET', '/index.php/csrftoken');
			self::assertSame(200, $current['status']);
			$cookies = [];
			foreach (curl_getinfo($browser, CURLINFO_COOKIELIST) as $cookie) {
				$parts = explode("\t", $cookie);
				$cookies[] = $parts[5] . '=' . $parts[6];
			}
			return ['Cookie: ' . implode('; ', $cookies), 'requesttoken: ' . json_decode($current['body'], true)['token']];
		} finally {
			unset($browser);
		}
	}

	/**
	 * @param \CurlHandle $browser In-memory cookie jar, following the existing ConsentHttpTest login path.
	 * @param string $method HTTP verb.
	 * @param string $path Core login/token route.
	 * @param array<string, mixed>|null $form Login form or no body.
	 * @return array{status:int, headers:string, body:string} Login response, never persisted with credentials.
	 */
	private function browse(\CurlHandle $browser, string $method, string $path, ?array $form = null): array {
		curl_setopt_array($browser, [CURLOPT_URL => Http::BASE . $path, CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_POSTFIELDS => $form === null ? null : http_build_query($form),
			CURLOPT_HTTPHEADER => $form === null ? ['Accept: text/html'] : ['Accept: text/html',
				'Origin: http://localhost', 'Content-Type: application/x-www-form-urlencoded']]);
		$raw = curl_exec($browser);
		self::assertIsString($raw);
		$size = (int)curl_getinfo($browser, CURLINFO_HEADER_SIZE);
		return ['status' => (int)curl_getinfo($browser, CURLINFO_RESPONSE_CODE),
			'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
	}

	/**
	 * @param string $method HTTP verb.
	 * @param list<string> $session Request credentials.
	 * @param array<string, mixed>|null $body Complete policy or invalid input.
	 * @return array{status:int, headers:string, body:string} Raw response to assert middleware outcomes.
	 */
	private function request(string $method, array $session, ?array $body = null): array {
		return Http::request($method, Http::BASE . '/index.php/apps/mcp/api/backup-settings',
			[...$session, 'Accept: application/json', 'Content-Type: application/json'],
			$body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
	}

	/** @return void restore absence in the disposable integration instance */
	protected function tearDown(): void {
		Server::get(IAppConfig::class)->deleteKey('mcp', BackupLocation::KEY);
		Server::get(IAppConfig::class)->clearCache(false);
		parent::tearDown();
	}

	/** @return void authenticated admin saves, reloads and receives conflicts without losing history */
	public function testAdminSaveReloadValidationAndRevision(): void {
		$session = $this->session('admin');
		$initial = $this->request('GET', $session);
		self::assertSame(200, $initial['status'], $initial['body']);
		$state = json_decode($initial['body'], true);
		self::assertSame('MCP backups', $state['folder']);
		self::assertTrue($state['copies']);
		$draft = ['expectedRevision' => $state['revision'], 'folder' => '.mcp-backups', 'copies' => false];
		$saved = $this->request('PUT', $session, $draft);
		self::assertSame(200, $saved['status'], $saved['body']);
		$state = json_decode($saved['body'], true);
		self::assertSame('.mcp-backups', $state['folder']);
		self::assertFalse($state['copies']);
		self::assertSame($saved['body'], $this->request('GET', $session)['body']);
		self::assertSame(409, $this->request('PUT', $session, $draft)['status']);
		self::assertSame(428, $this->request('PUT', $session, ['folder' => 'A', 'copies' => true])['status']);
		foreach (['a/b', "a\u{0085}"] as $invalid) {
			self::assertSame(400, $this->request('PUT', $session, ['expectedRevision' => $state['revision'], 'folder' => $invalid, 'copies' => true])['status']);
		}
		self::assertSame($saved['body'], $this->request('GET', $session)['body']);
	}

	/** @return void no anonymous, ordinary-user or forged-CSRF request can change backup settings */
	public function testFrameworkAuthenticationAdministratorAndCsrf(): void {
		$body = ['expectedRevision' => '0', 'folder' => 'A', 'copies' => true];
		$ordinary = $this->session(Fixtures::ALICE);
		foreach (['GET', 'PUT'] as $method) {
			$anonymous = $this->request($method, [], $method === 'PUT' ? $body : null);
			self::assertContains($anonymous['status'], [401, 403]);
			self::assertSame(403, $this->request($method, $ordinary, $method === 'PUT' ? $body : null)['status']);
		}
		$admin = $this->session('admin');
		$admin[1] = 'requesttoken: invalid';
		self::assertSame(412, $this->request('PUT', $admin, $body)['status']);
	}
}
