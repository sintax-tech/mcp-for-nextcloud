<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Deck\BoardBlueprint;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DeckRefusalException;
use OCA\Mcp\Tools\Deck\Handler\CreateBoardHandler;
use OCA\Mcp\Tools\ToolPresentation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/** What the person reads about boards and lists comes out in the language of the account. */
final class DeckStructureTranslationTest extends TestCase {
	use DeckTestHelpers;

	protected function tearDown(): void {
		Translator::reset();
	}

	public function testRefusalsAreWordedForThePerson(): void {
		Translator::use(new JsonL10n('pt_BR'));
		self::assertSame('A lista tem 3 cartões; mova ou exclua antes.', DeckRefusalException::stackNotEmpty(3)->getMessage());
		self::assertSame('A lista tem 1 cartão; mova ou exclua antes.', DeckRefusalException::stackNotEmpty(1)->getMessage());
		self::assertSame('O quadro tem 2 cartões; mova ou exclua antes.', DeckRefusalException::boardNotEmpty(2)->getMessage());
		self::assertSame('Só o dono de um quadro pode excluí-lo.', DeckRefusalException::boardNotOwned()->getMessage());

		Translator::use(new JsonL10n('es'));
		self::assertSame('La lista tiene 3 tarjetas; muévalas o elimínelas antes.', DeckRefusalException::stackNotEmpty(3)->getMessage());
		self::assertSame('El tablero tiene 1 tarjeta; muévala o elimínela antes.', DeckRefusalException::boardNotEmpty(1)->getMessage());
		self::assertSame('Solo el propietario de un tablero puede eliminarlo.', DeckRefusalException::boardNotOwned()->getMessage());
	}

	public function testPlanMessagesAndConsequencesAreTranslated(): void {
		foreach (['pt_BR', 'es'] as $language) {
			Translator::use(new JsonL10n($language));
			foreach ([
				'planCreateBoard', 'planCreateStack', 'planDeleteStack', 'planDeleteBoard',
				'planDeleteStackConsequence', 'planDeleteBoardConsequence',
			] as $method) {
				$text = [DeckMessages::class, $method]();
				Translator::reset();
				$english = [DeckMessages::class, $method]();
				Translator::use(new JsonL10n($language));
				self::assertNotSame($english, $text, $language . ' ' . $method);
			}
		}
	}

	public function testBlueprintRefusalsAreTranslated(): void {
		Translator::use(new JsonL10n('pt_BR'));
		try {
			BoardBlueprint::parse(['title' => 'P', 'stacks' => [['title' => 'L', 'cards' => [['title' => 'C', 'assignees' => ['pedro']]]]]], 'alice');
			self::fail('accepted another account');
		} catch (ArgumentValidationException $e) {
			self::assertStringContainsString('compartilhe o quadro primeiro', $e->clientMessage());
		}
	}

	public function testTheResultSummaryIsTranslated(): void {
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
		$gateway->method('createStack')->willReturn(new Stack(['id' => 10, 'title' => 'A fazer']));
		$gateway->method('createCard')->willReturn($this->card(['id' => 5]));
		$handler = new CreateBoardHandler($gateway, $this->createMock(LoggerInterface::class));
		Translator::use(new JsonL10n('pt_BR'));

		$payload = $this->payload($handler->handle(['title' => 'Projeto', 'stacks' => [['title' => 'A fazer', 'cards' => [['title' => 'Um']]]]], 'alice'));

		self::assertSame("O quadro 'Projeto' foi criado com 1 lista e 1 cartão.", $payload['summary']);
	}

	public function testToolTitlesAreTranslated(): void {
		Translator::use(new JsonL10n('pt_BR'));
		self::assertSame('Criar quadro no Deck', ToolPresentation::title('deck_create_board'));
		self::assertSame('Excluir lista do Deck', ToolPresentation::title('deck_delete_stack'));
		Translator::use(new JsonL10n('es'));
		self::assertSame('Crear tablero en Deck', ToolPresentation::title('deck_create_board'));
		self::assertSame('Eliminar tablero de Deck', ToolPresentation::title('deck_delete_board'));
	}
}
