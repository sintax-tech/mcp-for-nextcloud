<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\Support\Http;
use RuntimeException;

/** A real login-cookie and CSRF client of the Deck CardController, not its OCS/API controller. */
final class DeckBrowser {
	private \CurlHandle $browser;
	private string $csrf;

	/** @param string $uid Synthetic account. @param string $password Its fixture-created password. */
	public function __construct(string $uid, string $password) {
		$this->browser = curl_init();
		curl_setopt_array($this->browser, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEFILE => '']);
		$page = $this->request('GET', '/index.php/login');
		$this->csrf = self::token($page['body']);
		$login = $this->request('POST', '/index.php/login', ['user' => $uid, 'password' => $password, 'requesttoken' => $this->csrf]);
		if ($login['status'] !== 303) {
			throw new RuntimeException('Synthetic Deck browser login failed: HTTP ' . $login['status']);
		}
		$this->csrf = self::token($this->request('GET', '/index.php/apps/deck/')['body']);
	}

	/** @param string $html Native rendered page. @return string Native CSRF token. */
	private static function token(string $html): string {
		if (!preg_match('/data-requesttoken="([^"]+)"/', $html, $match)) {
			throw new RuntimeException('Native page has no CSRF token');
		}
		return html_entity_decode($match[1], ENT_QUOTES);
	}

	/**
	 * @param string $method HTTP verb.
	 * @param string $path Observed native route.
	 * @param array<string,mixed>|null $form Native form body.
	 * @param list<string> $headers Optional test probe header.
	 * @return array{status:int,body:string} Real route response.
	 */
	public function request(string $method, string $path, ?array $form = null, array $headers = []): array {
		curl_setopt_array($this->browser, [CURLOPT_URL => Http::BASE . $path, CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_POSTFIELDS => $form === null ? null : http_build_query($form), CURLOPT_HTTPHEADER => [
				'Accept: text/html, application/json', 'Origin: http://localhost',
				'Content-Type: application/x-www-form-urlencoded', ...$headers]]);
		$raw = curl_exec($this->browser);
		if (!is_string($raw)) {
			throw new RuntimeException('Native browser request failed');
		}
		return ['status' => (int)curl_getinfo($this->browser, CURLINFO_RESPONSE_CODE),
			'body' => substr($raw, (int)curl_getinfo($this->browser, CURLINFO_HEADER_SIZE))];
	}

	/** @param int $card Native card ID. @param string $uid Recipient. @param list<string> $headers Probe headers. @return array Real response. */
	public function assign(int $card, string $uid, array $headers = []): array {
		return $this->request('POST', '/index.php/apps/deck/cards/' . $card . '/assign',
			['userId' => $uid, 'type' => 0, 'requesttoken' => $this->csrf], ['requesttoken: ' . $this->csrf, ...$headers]);
	}

	/** Release the fixture's cookie handle. */
	public function close(): void {
		curl_close($this->browser);
	}
}
