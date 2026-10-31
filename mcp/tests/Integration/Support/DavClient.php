<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use RuntimeException;

/** Files changed from outside the MCP tools, through the server's own WebDAV endpoint (real core events). */
final class DavClient {
	public function __construct(private readonly Credential $credential) {
	}

	public function put(string $path, string $content): void {
		$this->send('PUT', $path, [201, 204], [], $content);
	}

	public function mkcol(string $path): void {
		$this->send('MKCOL', $path, [201]);
	}

	public function move(string $from, string $to): void {
		$this->send('MOVE', $from, [201, 204], ['Destination: ' . $this->url($to)]);
	}

	public function delete(string $path): void {
		$this->send('DELETE', $path, [204]);
	}

	private function url(string $path): string {
		$segments = array_map('rawurlencode', array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '')));
		return Http::BASE . '/remote.php/dav/files/' . rawurlencode($this->credential->uid) . '/' . implode('/', $segments);
	}

	/**
	 * @param list<int> $expected
	 * @param list<string> $headers
	 */
	private function send(string $method, string $path, array $expected, array $headers = [], ?string $body = null): void {
		$response = Http::request($method, $this->url($path), array_merge(['Authorization: ' . $this->credential->authorization()], $headers), $body);
		if (!in_array($response['status'], $expected, true)) {
			throw new RuntimeException("WebDAV $method $path answered HTTP {$response['status']}: " . substr($response['body'], 0, 400));
		}
	}
}
