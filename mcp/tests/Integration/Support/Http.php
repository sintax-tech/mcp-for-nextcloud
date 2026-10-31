<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use RuntimeException;

/** Plain HTTP to the Apache of the Nextcloud container, from the PHP CLI that runs the suite inside it. */
final class Http {
	public const BASE = 'http://localhost';

	/**
	 * @param list<string> $headers
	 * @return array{status:int, headers:string, body:string}
	 */
	public static function request(string $method, string $url, array $headers = [], ?string $body = null): array {
		$handle = curl_init($url);
		curl_setopt_array($handle, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_HTTPHEADER => $headers,
		]);
		if ($body !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		}
		$raw = curl_exec($handle);
		if (!is_string($raw)) {
			throw new RuntimeException("$method $url failed: " . curl_error($handle));
		}
		$size = (int)curl_getinfo($handle, CURLINFO_HEADER_SIZE);
		return [
			'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
			'headers' => substr($raw, 0, $size),
			'body' => substr($raw, $size),
		];
	}
}
