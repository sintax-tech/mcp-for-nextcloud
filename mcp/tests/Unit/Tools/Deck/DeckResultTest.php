<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Tools\Deck\DeckResult;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the MCP envelope built for the Deck tools.
 */
final class DeckResultTest extends TestCase {
	use DeckTestHelpers;

	public function testOkEncodesThePayloadAsJson(): void {
		$result = DeckResult::ok(['cards' => [['id' => 3]], 'total' => null]);

		self::assertArrayNotHasKey('isError', $result);
		self::assertSame('text', $result['content'][0]['type']);
		self::assertSame(
			['cards' => [['id' => 3]], 'total' => null],
			$this->payload($result),
		);
	}

	public function testOkKeepsAccentsAndSlashesReadable(): void {
		$result = DeckResult::ok(['title' => 'Reunião dezesete/mês']);

		self::assertStringContainsString('Reunião dezesete/mês', $result['content'][0]['text']);
	}

	public function testErrorFlagsIsErrorAndCarriesOnlyTheMessage(): void {
		$result = DeckResult::error('Não foi possível concluir a operação no Deck.');

		self::assertTrue($result['isError']);
		self::assertSame('Não foi possível concluir a operação no Deck.', $this->text($result));
	}
}
