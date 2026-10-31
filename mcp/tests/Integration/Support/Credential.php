<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

/** A real login of the proof: an app password for HTTP Basic, or an access token issued by the app for Bearer. */
final class Credential {
	private function __construct(
		public readonly string $kind,
		public readonly string $uid,
		private readonly string $secret,
	) {
	}

	public static function basic(string $uid, string $appPassword): self {
		return new self('basic', $uid, $appPassword);
	}

	public static function bearer(string $uid, string $accessToken): self {
		return new self('bearer', $uid, $accessToken);
	}

	/** @return string value of the Authorization header */
	public function authorization(): string {
		return $this->kind === 'basic' ? 'Basic ' . base64_encode($this->uid . ':' . $this->secret) : 'Bearer ' . $this->secret;
	}
}
