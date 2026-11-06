<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Modules\Files\Presentation\CheckoutMessages;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\ITempManager;

/** Stores bounded raw bytes and removes the temporary file after use. */
final class BodyReader {
	/** One extra byte detects oversized input without keeping the entire body. */
	private const BODY_SLACK = 1;

	/**
	 * @param ITempManager $tempManager Official temporary file producer.
	 * @param CheckoutRefusals $refusals Safe body refusals.
	 */
	public function __construct(
		private ITempManager $tempManager,
		private CheckoutRefusals $refusals,
	) {
	}

	/**
	 * Accept raw file bytes regardless of the advertised MIME type, including a missing header.
	 * Multipart forms still need a targeted refusal because their envelope is not file content.
	 *
	 * @return Response|null the refusal, or null when the body type is acceptable
	 * @param string $contentType Raw Content-Type header.
	 */
	public function checkBodyType(string $contentType): ?Response {
		$type = strtolower(trim(explode(';', $contentType)[0]));
		if ($type === 'multipart/form-data') {
			return $this->refusals->refuse(Http::STATUS_BAD_REQUEST, CheckoutMessages::uploadMultipart());
		}
		return null;
	}

	/**
	 * @param int $limit effective upload limit in bytes
	 * @param bool $allowEmpty whether an empty body is a valid upload, true only for a create link
	 * @return Response|null the refusal, or null when the body is usable
	 * @param string $contentLength Raw Content-Length header.
	 */
	public function checkBody(string $contentLength, int $limit, bool $allowEmpty = false): ?Response {
		$declared = (int)$contentLength;
		if ($declared === 0 && !$allowEmpty) {
			return $this->refusals->refuse(Http::STATUS_BAD_REQUEST, CheckoutMessages::uploadEmpty());
		}
		if ($declared > $limit) {
			return $this->refusals->refuse(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, CheckoutMessages::uploadTooLarge($limit));
		}
		return null;
	}

	/**
	 * Copies the request body into a temporary file without ever holding more than the limit in memory.
	 *
	 * @param int $limit effective upload limit in bytes
	 * @return string|null the temporary path, or null when the body is over the limit or unreadable
	 * @param \Closure $openInput Opens raw input at the original point.
	 */
	private function storeBody(int $limit, \Closure $openInput): ?string {
		$path = $this->tempManager->getTemporaryFile('.mcp-upload');
		$input = $openInput();
		if ($path === false || $input === false) {
			return null;
		}
		$output = @fopen($path, 'wb');
		if ($output === false) {
			fclose($input);
			return null;
		}
		try {
			$copied = stream_copy_to_stream($input, $output, $limit + self::BODY_SLACK);
		} finally {
			fclose($input);
			fclose($output);
		}
		if ($copied === false || $copied > $limit) {
			@unlink($path);
			return null;
		}
		return $path;
	}

	/**
	 * @param int $limit Effective byte limit.
	 * @param \Closure $openInput Opens the raw request body at the original point.
	 * @param \Closure(string): Response $work Uses the stored body.
	 * @param \Closure(): Response $failure Reports a body that could not be stored.
	 * @return Response The work result or the same oversized/unreadable refusal.
	 */
	public function withBody(int $limit, \Closure $openInput, \Closure $work, \Closure $failure): Response {
		$body = $this->storeBody($limit, $openInput);
		if ($body === null) {
			return $failure();
		}
		try {
			return $work($body);
		} finally {
			@unlink($body);
		}
	}
}
