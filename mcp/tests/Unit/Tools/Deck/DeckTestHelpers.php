<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Card;

/**
 * Shared helpers for the Deck unit tests.
 *
 * Deck doubles live in `Stubs/`, which a Deck test must require before touching the module.
 */
trait DeckTestHelpers {
	/**
	 * Builds a card double.
	 *
	 * @param array<string, mixed> $fields Values the card getters report.
	 * @return Card Card double ready to be handed to a formatter or a gateway mock.
	 */
	protected function card(array $fields = []): Card {
		return new Card($fields + [
			'id' => 1,
			'stackId' => 10,
			'title' => 'Card',
			'description' => '',
			'type' => 'note',
			'owner' => 'alice',
			'order' => 0,
			'archived' => false,
			'deletedAt' => 0,
			'lastModified' => 1_700_000_000,
		]);
	}

	/**
	 * Decodes the JSON payload of a successful MCP result.
	 *
	 * @param array{content: list<array{type: string, text: string}>} $result Result returned by a handler.
	 * @return array<string, mixed> Decoded payload.
	 */
	protected function payload(array $result): array {
		self::assertArrayHasKey('content', $result);
		self::assertSame('text', $result['content'][0]['type']);

		$decoded = json_decode($result['content'][0]['text'], true);
		self::assertIsArray($decoded, 'The result text must be a JSON object.');

		return $decoded;
	}

	/**
	 * Reads the single text block of a result, successful or not.
	 *
	 * @param array{content: list<array{type: string, text: string}>} $result Result returned by a handler.
	 * @return string The text block.
	 */
	protected function text(array $result): string {
		self::assertArrayHasKey('content', $result);

		return $result['content'][0]['text'];
	}
}
