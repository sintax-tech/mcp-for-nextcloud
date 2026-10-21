<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Presentation;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Structure\BoardBlueprint;
use OCA\Mcp\Tests\Support\Deck\DeckDoubles;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Tools\CreateBoard;
use OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/** What the person reads about boards and lists comes out in the language of the account. */

final class DeckStructureTranslationTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
    protected function tearDown(): void {
        Translator::reset();
    }

    public function testProbableMessagesAreWordedForThePerson(): void {
        Translator::use(new JsonL10n('pt_BR'));
        self::assertSame(
            'Provavelmente criado: confira antes de continuar.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probablyCreated(),
        );
        self::assertSame(
            'Os responsáveis não foram atribuídos porque o cartão foi apenas provavelmente criado. Confira-o antes.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableCardNotAssigned(),
        );
        self::assertSame(
            'Não criado: aquilo a que pertence foi apenas provavelmente criado. Confira e depois crie este.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableParentNotBuilt(),
        );
        Translator::use(new JsonL10n('es'));
        self::assertSame(
            'Probablemente creado: compruébelo antes de continuar.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probablyCreated(),
        );
        self::assertSame(
            'No se asignó a los responsables porque la tarjeta solo se creó probablemente. Compruébela antes.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableCardNotAssigned(),
        );
        self::assertSame(
            'No creado: aquello a lo que pertenece solo se creó probablemente. Compruébelo y luego cree este.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::probableParentNotBuilt(),
        );
    }

    public function testRefusalsAreWordedForThePerson(): void {
        Translator::use(new JsonL10n('pt_BR'));
        self::assertSame(
            'A lista tem 3 cartões; mova ou exclua antes.',
            DeckRefusalException::stackNotEmpty(3)->getMessage(),
        );
        self::assertSame('A lista tem 1 cartão; mova ou exclua antes.', DeckRefusalException::stackNotEmpty(1)->getMessage());
        self::assertSame(
            'O quadro tem 2 cartões; mova ou exclua antes.',
            DeckRefusalException::boardNotEmpty(2)->getMessage(),
        );
        self::assertSame('Só o dono de um quadro pode excluí-lo.', DeckRefusalException::boardNotOwned()->getMessage());
        self::assertSame(
            'A lista recebeu cartões durante a exclusão; nada foi excluído.',
            DeckRefusalException::stackReceivedCards(true)->getMessage(),
        );
        self::assertSame(
            'O quadro recebeu cartões durante a exclusão; nada foi excluído.',
            DeckRefusalException::boardReceivedCards(true)->getMessage(),
        );
        self::assertSame(
            'A lista recebeu cartões durante a exclusão e não pôde ser restaurada; recupere-a na lixeira do Deck.',
            DeckRefusalException::stackReceivedCards(false)->getMessage(),
        );
        self::assertSame(
            'O quadro recebeu cartões durante a exclusão e não pôde ser restaurado; recupere-o na lixeira do Deck.',
            DeckRefusalException::boardReceivedCards(false)->getMessage(),
        );
        Translator::use(new JsonL10n('es'));
        self::assertSame(
            'La lista tiene 3 tarjetas; muévalas o elimínelas antes.',
            DeckRefusalException::stackNotEmpty(3)->getMessage(),
        );
        self::assertSame(
            'El tablero tiene 1 tarjeta; muévala o elimínela antes.',
            DeckRefusalException::boardNotEmpty(1)->getMessage(),
        );
        self::assertSame(
            'Solo el propietario de un tablero puede eliminarlo.',
            DeckRefusalException::boardNotOwned()->getMessage(),
        );
        self::assertSame(
            'La lista recibió tarjetas durante la eliminación; no se eliminó nada.',
            DeckRefusalException::stackReceivedCards(true)->getMessage(),
        );
        self::assertSame(
            'El tablero recibió tarjetas durante la eliminación; no se eliminó nada.',
            DeckRefusalException::boardReceivedCards(true)->getMessage(),
        );
        self::assertSame(
            'La lista recibió tarjetas durante la eliminación y no se pudo restaurar; recupérela desde la papelera de Deck.',
            DeckRefusalException::stackReceivedCards(false)->getMessage(),
        );
        self::assertSame(
            'El tablero recibió tarjetas durante la eliminación y no se pudo restaurar; recupérelo desde la papelera de Deck.',
            DeckRefusalException::boardReceivedCards(false)->getMessage(),
        );
    }

    /** Re-review of c06b2e0: what a write answers when Deck failed after saving, or nobody can tell. */
    public function testAfterWriteMessagesAreWordedForThePerson(): void {
        Translator::use(new JsonL10n('pt_BR'));
        self::assertSame(
            'Foi gravado; o Deck relatou um erro depois (notificação ou atividade).',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writtenThenFailed(),
        );
        self::assertSame(
            'Não foi possível confirmar se o Deck gravou esta alteração; leia com deck_read_card antes de tentar de novo.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writeUnconfirmed('deck_read_card'),
        );
        self::assertSame(
            "Não foi possível confirmar se a lista 'A fazer' foi criada; leia o quadro antes de tentar de novo.",
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::boardItemUnconfirmed('list', 'A fazer'),
        );
        self::assertSame(
            "Não foi possível confirmar se o cartão 'Um' foi criado; leia a lista antes de tentar de novo.",
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::boardItemUnconfirmed('card', 'Um'),
        );
        Translator::use(new JsonL10n('es'));
        self::assertSame(
            'Se guardó; Deck informó un error después (notificación o actividad).',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writtenThenFailed(),
        );
        self::assertSame(
            'No se pudo confirmar si Deck guardó este cambio; léalo con deck_list_boards antes de volver a intentarlo.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::writeUnconfirmed('deck_list_boards'),
        );
        self::assertSame(
            "No se pudo confirmar si se creó la lista 'A fazer'; lea el tablero antes de volver a intentarlo.",
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::boardItemUnconfirmed('list', 'A fazer'),
        );
        self::assertSame(
            "No se pudo confirmar si se creó la tarjeta 'Um'; lea la lista antes de volver a intentarlo.",
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::boardItemUnconfirmed('card', 'Um'),
        );
    }

    /** A deck_create_board with an item nobody can confirm says so in the summary, in the language of the account. */
    public function testTheSummaryOfAnUnconfirmedBuildIsTranslated(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $capabilities = $this->deckDoubles();
        $capabilities->boardCreator->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
        $capabilities->stackCreator->method('createStack')->willThrowException(new \RuntimeException('listener'));
        $capabilities->structureState->method('stacksOf')->willThrowException(new \RuntimeException('db gone'));
        $payload = $this->payload($this->deckTool(CreateBoard::class, $capabilities, $this->createMock(LoggerInterface::class))->execute(
            ['title' => 'Projeto', 'stacks' => [['title' => 'A fazer']], 'confirm' => true],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
        self::assertStringEndsWith(
            " Alguns itens não puderam ser confirmados; veja 'warnings' antes de tentar de novo.",
            $payload['summary'],
        );
    }

    public function testPlanMessagesAndConsequencesAreTranslated(): void {
        foreach (['pt_BR', 'es'] as $language) {
            Translator::use(new JsonL10n($language));
            foreach ([
                'planCreateBoard',
                'planCreateStack',
                'planDeleteStack',
                'planDeleteBoard',
                'planDeleteStackConsequence',
                'planDeleteBoardConsequence',
            ] as $method) {
                $text = [\OCA\Mcp\Modules\Deck\Structure\StructureMessages::class, $method]();
                Translator::reset();
                $english = [\OCA\Mcp\Modules\Deck\Structure\StructureMessages::class, $method]();
                Translator::use(new JsonL10n($language));
                self::assertNotSame($english, $text, $language . ' ' . $method);
            }
        }
    }

    public function testBlueprintRefusalsAreTranslated(): void {
        Translator::use(new JsonL10n('pt_BR'));
        try {
            BoardBlueprint::parse(
                [
                    'title' => 'P',
                    'stacks' => [['title' => 'L', 'cards' => [['title' => 'C', 'assignees' => ['pedro']]]]],
                ],
                'alice',
            );
            self::fail('accepted another account');
        } catch (ArgumentValidationException $e) {
            self::assertStringContainsString('compartilhe o quadro primeiro', $e->clientMessage());
        }
    }

    public function testTheResultSummaryIsTranslated(): void {
        $capabilities = $this->deckDoubles();
        $capabilities->boardCreator->method('createBoard')->willReturn(new Board(['id' => 3, 'title' => 'Projeto']));
        $capabilities->stackCreator->method('createStack')->willReturn(new Stack(['id' => 10, 'title' => 'A fazer']));
        $capabilities->writer->method('createCard')->willReturn($this->card(['id' => 5]));
        $handler = $this->deckTool(CreateBoard::class, $capabilities, $this->createMock(LoggerInterface::class));
        Translator::use(new JsonL10n('pt_BR'));
        $payload = $this->payload($handler->execute(
            ['title' => 'Projeto', 'stacks' => [['title' => 'A fazer', 'cards' => [['title' => 'Um']]]]],
            new \OCA\Mcp\Mcp\Tools\ToolContext('alice'),
        ));
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
