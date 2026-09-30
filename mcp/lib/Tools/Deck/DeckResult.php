<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

/**
 * Builds the MCP result envelope for the Deck tools.
 *
 * Success carries a JSON payload in a single text block; failure carries a generic
 * message chosen by {@see DeckErrors}. Exception messages never reach the client.
 */
final class DeckResult {
	/** Flags that keep UTF-8 accents readable and slashes unescaped in the JSON payload. */
	private const ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	/**
	 * Successful result carrying the JSON-encoded payload.
	 *
	 * @param mixed $payload Value to encode as the single text content block.
	 * @return array{content: list<array{type: string, text: string}>}
	 * @throws \JsonException When the payload cannot be encoded.
	 */
	public static function ok(mixed $payload): array {
		return [
			'content' => [
				['type' => 'text', 'text' => self::encode($payload)],
			],
		];
	}

	/**
	 * Failed result carrying a caller-safe message.
	 *
	 * @param string $message Generic message, already free of any internal detail.
	 * @return array{content: list<array{type: string, text: string}>, isError: true}
	 */
	public static function error(string $message): array {
		return [
			'content' => [
				['type' => 'text', 'text' => $message],
			],
			'isError' => true,
		];
	}

	/**
	 * @param mixed $payload
	 * @return string
	 * @throws \JsonException
	 */
	private static function encode(mixed $payload): string {
		return json_encode($payload, self::ENCODE_FLAGS);
	}
}
