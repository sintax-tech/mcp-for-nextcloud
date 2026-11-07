<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Clients;

use Closure;
use RuntimeException;

/**
 * One total time budget for downloading a client metadata document, started when the object is created.
 *
 * The authorization page waits for this download before it shows anything, so the budget covers the connection, the
 * TLS handshake and every read of the body together. Nextcloud's IClient runs Guzzle on its curl handler, where
 * `timeout` is curl's total transfer limit and the body is already buffered when the response returns; the read loop
 * still counts against the same budget, so the limit holds with a handler that streams from the socket. DNS lookups
 * done by Nextcloud's DNS pinning before the transfer use the system resolver and are outside this budget.
 */
final class ClientMetadataDeadline {
	/** Seconds allowed for the whole download: connection, response and body. */
	public const TOTAL_SECONDS = 5;

	/** Largest single read from the body. */
	private const CHUNK_BYTES = 8192;

	/** @var Closure(): float monotonic clock in seconds */
	private Closure $clock;

	private float $deadline;

	/** True once a read waited for all the time that was left without getting its data. */
	private bool $exhausted = false;

	/**
	 * @param int $seconds total budget in seconds, from now
	 * @param Closure(): float|null $clock monotonic clock in seconds; null uses hrtime()
	 */
	public function __construct(int $seconds = self::TOTAL_SECONDS, ?Closure $clock = null) {
		$this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
		$this->deadline = ($this->clock)() + $seconds;
	}

	/**
	 * Guzzle options that bound the connection and the whole transfer by the time left.
	 *
	 * @return array{timeout: int, connect_timeout: int} whole seconds left, at least 1 (0 would mean no limit)
	 */
	public function requestOptions(): array {
		$seconds = max(1, (int)ceil($this->remaining()));
		return ['timeout' => $seconds, 'connect_timeout' => $seconds];
	}

	/**
	 * @return bool true once the budget is used up, or once a read timed out after being given all the time left
	 */
	public function expired(): bool {
		return $this->exhausted || $this->remaining() <= 0.0;
	}

	/**
	 * Reads the body until the end of the stream or one byte past the limit, giving every read only the time left.
	 *
	 * @param resource $stream response body
	 * @param int $maxBytes size limit; up to $maxBytes + 1 bytes are read so the caller can tell an oversized body
	 * @return string the bytes read
	 * @throws RuntimeException when the budget runs out or the stream fails
	 */
	public function read($stream, int $maxBytes): string {
		$body = '';
		while (!feof($stream) && strlen($body) <= $maxBytes) {
			$remaining = $this->remaining();
			if ($remaining <= 0.0) {
				throw new RuntimeException('Client metadata download deadline reached');
			}
			// Sockets honour this; memory and temp streams (a buffered body) ignore it and never block.
			stream_set_timeout($stream, (int)$remaining, (int)(fmod($remaining, 1.0) * 1e6));
			$chunk = fread($stream, min(self::CHUNK_BYTES, $maxBytes + 1 - strlen($body)));
			if (stream_get_meta_data($stream)['timed_out'] ?? false) {
				$this->exhausted = true;
				throw new RuntimeException('Client metadata download deadline reached');
			}
			if ($chunk === false) {
				throw new RuntimeException('Client metadata body read failed');
			}
			$body .= $chunk;
		}
		return $body;
	}

	/**
	 * @return float seconds left, negative once the budget is used up
	 */
	private function remaining(): float {
		return $this->deadline - ($this->clock)();
	}
}
