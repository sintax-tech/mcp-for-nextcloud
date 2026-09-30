<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Card;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Shared helpers for the Deck unit tests.
 *
 * Deck doubles live in `Stubs/`, which a Deck test must require before touching the module.
 */
trait DeckTestHelpers {
	/**
	 * Builds a formatter with doubles for the collaborators every card payload needs.
	 *
	 * The defaults answer an empty link, the epoch as clock and no display name, which is what a
	 * test that only cares about the card fields wants; a test about `url`, `overdue` or a name
	 * passes its own double.
	 *
	 * @param IURLGenerator|null $urls Link builder, or null for a double that answers an empty link.
	 * @param ITimeFactory|null $time Clock, or null for a double stuck at the epoch.
	 * @param IUserManager|null $users Account lookup, or null for a double that resolves nothing.
	 * @return CardFormatter Formatter ready for a handler or a direct call.
	 */
	protected function cardFormatter(
		?IURLGenerator $urls = null,
		?ITimeFactory $time = null,
		?IUserManager $users = null,
	): CardFormatter {
		return new CardFormatter(
			$urls ?? $this->createMock(IURLGenerator::class),
			$time ?? $this->createMock(ITimeFactory::class),
			$users ?? $this->createMock(IUserManager::class),
		);
	}

	/**
	 * Builds a gateway double whose ownership lookups report a board of the given owner.
	 *
	 * Every write handler asks for the ownership before touching the Deck, so a double of a write
	 * handler has to answer it; `alice` is the caller and owns `Pessoal`, which lets the write go
	 * through. Tests about somebody else's board pass another owner.
	 *
	 * @param string $owner UID reported as owner of the board of both the card and the stack.
	 * @param string $name Board title reported by the ownership lookups.
	 * @return DeckGatewayInterface&MockObject Gateway double, ready for the write expectations.
	 */
	protected function gatewayOwnedBy(string $owner, string $name = 'Pessoal'): DeckGatewayInterface&MockObject {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$ownership = $this->ownershipOf($owner, $name);
		$gateway->method('cardOwnership')->willReturn($ownership);
		$gateway->method('stackOwnership')->willReturn($ownership);

		return $gateway;
	}

	/**
	 * Ownership report a gateway returns for a board.
	 *
	 * @param string $owner UID of the board owner.
	 * @param string $name Board title.
	 * @return array{owner: string, ownerDisplayName: string, name: string} Ownership report.
	 */
	protected function ownershipOf(string $owner, string $name = 'Pessoal'): array {
		$displayNames = ['alice' => 'Alice Silva', 'pedro' => 'Pedro Almeida'];

		return [
			'owner' => $owner,
			'ownerDisplayName' => $displayNames[$owner] ?? $owner,
			'name' => $name,
		];
	}

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

	/**
	 * Asserts a result is the shared-resource confirmation of `pedro`'s board, with no error.
	 *
	 * @param array{content: list<array{type: string, text: string}>} $result Result returned by a handler.
	 */
	protected function assertSharedConfirmation(array $result): void {
		self::assertArrayNotHasKey('isError', $result);
		$payload = $this->payload($result);
		self::assertTrue($payload['requiresConfirmation']);
		self::assertSame('shared', $payload['scope']);
		self::assertSame('pedro', $payload['owner']);
		self::assertSame('Pedro Almeida', $payload['ownerDisplayName']);
		self::assertSame('Comercial', $payload['resource']);
		self::assertSame(
			"O quadro 'Comercial' pertence a Pedro Almeida e é compartilhado com você. Alterações afetam outras pessoas."
			. ' Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.',
			$payload['message'],
		);
	}
}
