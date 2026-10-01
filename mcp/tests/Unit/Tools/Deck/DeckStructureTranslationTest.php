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
		self::assertSame('A lista recebeu cartões durante a exclusão; nada foi excluído.', DeckRefusalException::stackReceivedCards(true)->getMessage());
		self::assertSame('O quadro recebeu cartões durante a exclusão; nada foi excluído.', DeckRefusalException::boardReceivedCards(true)->getMessage());
		self::assertSame('A lista recebeu cartões durante a exclusão e não pôde ser restaurada; recupere-a na lixeira do Deck.', DeckRefusalException::stackReceivedCards(false)->getMessage());
		self::assertSame('O quadro recebeu cartões durante a exclusão e não pôde ser restaurado; recupere-o na lixeira do Deck.', DeckRefusalException::boardReceivedCards(false)->getMessage());

		Translator::use(new JsonL10n('es'));
		self::assertSame('La lista tiene 3 tarjetas; muévalas o elimínelas antes.', DeckRefusalException::stackNotEmpty(3)->getMessage());
		self::assertSame('El tablero tiene 1 tarjeta; muévala o elimínela antes.', DeckRefusalException::boardNotEmpty(1)->getMessage());
		self::assertSame('Solo el propietario de un tablero puede eliminarlo.', DeckRefusalException::boardNotOwned()->getMessage());
		self::assertSame('La lista recibió tarjetas durante la eliminación; no se eliminó nada.', DeckRefusalException::stackReceivedCards(true)->getMessage());
		self::assertSame('El tablero recibió tarjetas durante la eliminación; no se eliminó nada.', DeckRefusalException::boardReceivedCards(true)->getMessage());
		self::assertSame('La lista recibió tarjetas durante la eliminación y no se pudo restaurar; recupérela desde la papelera de Deck.', DeckRefusalException::stackReceivedCards(false)->getMessage());
		self::assertSame('El tablero recibió tarjetas durante la eliminación y no se pudo restaurar; recupérelo desde la papelera de Deck.', DeckRefusalException::boardReceivedCards(false)->getMessage());
	}

	/** Re-review of c06b2e0: what a write answers when Deck failed after saving, or nobody can tell. */
	public function testAfterWriteMessagesAreWordedForThePerson(): void {
		Translator::use(new JsonL10n('pt_BR'));
		self::assertSame('Foi gravado; o Deck relatou um erro depois (notificação ou atividade).', DeckMessages::writtenThenFailed());
		self::assertSame('Não foi possível confirmar se o Deck gravou esta alteração; leia com deck_read_card antes de tentar de novo.', DeckMessages::writeUnconfirmed('deck_read_card'));
		self::assertSame("Não foi possível confirmar se a lista 'A fazer' foi criada; leia o quadro antes de tentar de novo.", DeckMessages::boardItemUnconfirmed('list', 'A fazer'));
		self::assertSame("Não foi possível confirmar se o cartão 'Um' foi criado; leia a lista antes de tentar de novo.", DeckMessages::boardItemUnconfirmed('card', 'Um'));

		Translator::use(new JsonL10n('es'));
		self::assertSame('Se guardó; Deck informó un error después (notificación o actividad).', DeckMessages::writtenThenFailed());
		self::assertSame('No se pudo confirmar si Deck guardó este cambio; léalo con deck_list_boards antes de volver a intentarlo.', DeckMessages::writeUnconfirmed('deck_list_boards'));
		self::assertSame("No se pudo confirmar si se creó la lista 'A fazer'; lea el tablero antes de volver a intentarlo.", DeckMessages::boardItemUnconfirmed('list', 'A fazer'));
		self::assertSame("No se pudo confirmar si se creó la tarjeta 'Um'; lea la lista antes de volver a intentarlo.", DeckMessages::boardItemUnconfirmed('card', 'Um'));
	}

	/** A deck_create_board with an item nobody can confirm says so in the summary, in the language of the account. */
	public function testTheSummaryOfAnUnconfirmedBuildIsTranslated(): void {
		Translator::use(new JsonL10n('pt_BR'));
		$gateway = $this->createMock(DeckGatewayInterface::class);
		$gateway->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
		$gateway->method('createStack')->willThrowException(new \RuntimeException('listener'));
		$gateway->method('stacksOf')->willThrowException(new \RuntimeException('db gone'));

		$payload = $this->payload((new CreateBoardHandler($gateway, $this->createMock(LoggerInterface::class)))
			->handle(['title' => 'Projeto', 'stacks' => [['title' => 'A fazer']], 'confirm' => true], 'alice'));

		self::assertStringEndsWith(" Alguns itens não puderam ser confirmados; veja 'warnings' antes de tentar de novo.", $payload['summary']);
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
