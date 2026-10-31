<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

/** The answer of one tools/call over HTTP. */
final class ToolReply {
	/** @param array<string, mixed> $body decoded JSON-RPC envelope, empty when the body was not JSON */
	public function __construct(
		public readonly int $status,
		public readonly array $body,
		public readonly string $raw,
	) {
	}

	/** @return array<string, mixed> */
	public function result(): array {
		$result = $this->body['result'] ?? [];
		return is_array($result) ? $result : [];
	}

	/** @return bool the tool answered with isError: true */
	public function isError(): bool {
		return ($this->result()['isError'] ?? false) === true;
	}

	/** @return bool HTTP 200, no JSON-RPC error, and the tool did not report an error */
	public function ok(): bool {
		return $this->status === 200 && !isset($this->body['error']) && $this->result() !== [] && !$this->isError();
	}

	/** @return string every text block of the answer, in order */
	public function text(): string {
		$texts = [];
		foreach ($this->result()['content'] ?? [] as $block) {
			if (is_array($block) && ($block['type'] ?? '') === 'text') {
				$texts[] = (string)($block['text'] ?? '');
			}
		}
		return implode("\n", $texts);
	}

	/** @return mixed the first text block decoded as JSON, null when it is not JSON */
	public function json(): mixed {
		foreach ($this->result()['content'] ?? [] as $block) {
			if (is_array($block) && ($block['type'] ?? '') === 'text') {
				return json_decode((string)($block['text'] ?? ''), true);
			}
		}
		return null;
	}

	/** @return array<string, mixed> */
	public function structured(): array {
		$data = $this->result()['structuredContent'] ?? [];
		return is_array($data) ? $data : [];
	}

	/** @return string everything a client reads of the answer: the HTTP status and the whole result (content, structuredContent, _meta, isError) */
	public function fingerprint(): string {
		return $this->status . ' ' . json_encode($this->result(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/** @return string a short account for assertion messages */
	public function describe(): string {
		return 'HTTP ' . $this->status . ' ' . substr($this->raw, 0, 600);
	}
}
