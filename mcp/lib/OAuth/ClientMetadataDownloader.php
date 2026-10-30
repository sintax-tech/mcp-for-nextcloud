<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use Closure;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use RuntimeException;
use Throwable;

/**
 * Downloads an allowlisted client metadata document within one total time budget (ClientMetadataDeadline), without
 * following redirects and within the size limit.
 *
 * A failed download is remembered for that client_id only, for a short time, so a client whose server does not answer
 * fails at once on the next attempts instead of making every user wait for the budget again. Only failures are kept
 * here: a document is never stored by this class, and the failure entry of one client_id is never read for another.
 */
final class ClientMetadataDownloader {
	/** Size limit of a metadata document. */
	public const MAX_BYTES = 65536;

	/** Seconds a failed download is remembered for its client_id. */
	public const FAILURE_TTL = 30;

	/** Shown when the budget ran out before the whole document arrived. */
	public const TIMEOUT_MESSAGE = 'The client did not send its metadata document in time. Try connecting again shortly.';

	/** Shown for every other download failure. */
	public const UNAVAILABLE_MESSAGE = 'Client metadata document unavailable';

	/**
	 * @param IClientService $clientService Nextcloud HTTP client (refuses local and private addresses)
	 * @param ICache $cache the metadata document cache; failures use their own keys in it
	 * @param int $seconds total budget of one download
	 * @param Closure(): float|null $clock monotonic clock in seconds; null uses hrtime()
	 */
	public function __construct(
		private IClientService $clientService,
		private ICache $cache,
		private int $seconds = ClientMetadataDeadline::TOTAL_SECONDS,
		private ?Closure $clock = null,
	) {
	}

	/**
	 * @param string $clientId validated, allowlisted HTTPS metadata document URL
	 * @return string metadata document body within the size limit
	 * @throws OAuthException invalid_client (never redirectable) when the document cannot be fetched in time or in size,
	 *     or when this client_id failed within the last FAILURE_TTL seconds
	 */
	public function download(string $clientId): string {
		$failureKey = 'failed-' . hash('sha256', $clientId);
		$failure = $this->cache->get($failureKey);
		if ($failure === self::TIMEOUT_MESSAGE || $failure === self::UNAVAILABLE_MESSAGE) {
			throw new OAuthException('invalid_client', $failure);
		}
		$deadline = new ClientMetadataDeadline($this->seconds, $this->clock);
		try {
			return $this->fetch($clientId, $deadline);
		} catch (Throwable) {
			$message = $deadline->expired() ? self::TIMEOUT_MESSAGE : self::UNAVAILABLE_MESSAGE;
			$this->cache->set($failureKey, $message, self::FAILURE_TTL);
			throw new OAuthException('invalid_client', $message);
		}
	}

	/**
	 * @param string $clientId metadata document URL
	 * @param ClientMetadataDeadline $deadline budget of this download
	 * @return string the body, at most MAX_BYTES long
	 * @throws Throwable on any HTTP, status, size or time failure
	 */
	private function fetch(string $clientId, ClientMetadataDeadline $deadline): string {
		$stream = null;
		try {
			$response = $this->clientService->newClient()->get($clientId, $deadline->requestOptions() + [
				'allow_redirects' => false,
				// OCP IResponse returns a resource with stream enabled, avoiding eager buffering.
				'stream' => true,
				'headers' => ['Accept' => 'application/json'],
			]);
			$stream = $response->getBody();
			$length = $response->getHeader('Content-Length');
			if ($response->getStatusCode() !== 200 || !is_resource($stream)
				|| ($length !== '' && (!ctype_digit($length) || (float)$length > self::MAX_BYTES))) {
				throw new RuntimeException('Invalid metadata response');
			}
			$body = $deadline->read($stream, self::MAX_BYTES);
			if (strlen($body) > self::MAX_BYTES) {
				throw new RuntimeException('Metadata exceeds size limit');
			}
			return $body;
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}
}
