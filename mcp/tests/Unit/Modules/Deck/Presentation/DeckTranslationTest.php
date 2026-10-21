<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Presentation;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Modules\Deck\Execution\DeckErrors;
use OCA\Mcp\Modules\Deck\DeckModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';
/** What the user of the Deck module sees comes out in the language of the account; what the model reads stays English. */

final class DeckTranslationTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
    use \OCA\Mcp\Tests\Support\Deck\DeckNativeFixtures;
    /** @var array<string, string> method of DeckMessages and the pt-BR text the app showed before the translation */
    private const PORTUGUESE = [
        'errorNotFoundOrForbidden' => 'Card, lista ou quadro não encontrado ou sem permissão.',
        'errorNotAllowed' => 'Operação não permitida neste board ou card do Deck.',
        'errorInvalid' => 'Dados inválidos para o Deck.',
        'errorConflict' => 'O item do Deck mudou durante a operação. Tente de novo.',
        'errorGeneric' => 'Não foi possível concluir a operação no Deck.',
        'errorNoFieldToEdit' => 'Informe ao menos um campo para editar: title, description, duedate, assign ou unassign.',
        'errorInvalidDuedate' => 'A data prevista deve ser uma data válida no formato AAAA-MM-DD.',
        'errorInvalidDueBefore' => 'A data de dueBefore deve ser uma data válida no formato AAAA-MM-DD.',
        'errorInvalidStatus' => 'Status inválido para o acompanhamento. Use overdue, open, done ou all.',
        'errorDescriptionTooLong' => 'A descrição do card excede o tamanho máximo de 100000 caracteres.',
        'errorTitleRequired' => 'O título do card não pode ficar vazio.',
        'errorUnknownTool' => 'Ferramenta do Deck desconhecida.',
        'errorDeckAppUnavailable' => 'O app Deck não está disponível para este usuário.',
        'errorSessionNotBound' => 'Não foi possível vincular o Deck à conta autenticada. Nada foi alterado; tente de novo.',
    ];
    protected function tearDown(): void {
        Translator::reset();
    }

    public function testPortugueseUserGetsTheSameTextsAsBefore(): void {
        Translator::use(new JsonL10n('pt_BR'));
        foreach (self::PORTUGUESE as $method => $text) {
            self::assertSame($text, [\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::class, $method](), $method);
        }
        self::assertSame(
            "O quadro 'Comercial' pertence a Paulo Exemplo e é compartilhado com você. Alterações afetam outras pessoas." . ' Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.',
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::sharedConfirmation('Comercial', 'Paulo Exemplo'),
        );
    }

    /** The creator owns the card but is not assigned to it: no language may tell the user they became its assignee. */
    public function testCreatePlanDoesNotSayTheUserBecomesTheAssignee(): void {
        self::assertStringContainsString('owned by you', \OCA\Mcp\Modules\Deck\Cards\CardMessages::planCreate());
        self::assertStringContainsString('not assigned', \OCA\Mcp\Modules\Deck\Cards\CardMessages::planCreate());
        Translator::use(new JsonL10n('pt_BR'));
        self::assertStringNotContainsString('responsável', \OCA\Mcp\Modules\Deck\Cards\CardMessages::planCreate());
        self::assertStringContainsString('você é o dono', \OCA\Mcp\Modules\Deck\Cards\CardMessages::planCreate());
        Translator::use(new JsonL10n('es'));
        self::assertStringNotContainsString('responsable', \OCA\Mcp\Modules\Deck\Cards\CardMessages::planCreate());
        self::assertStringContainsString('propietario', \OCA\Mcp\Modules\Deck\Cards\CardMessages::planCreate());
    }

    public function testSpanishUserGetsSpanishTexts(): void {
        $english = [];
        foreach (array_keys(self::PORTUGUESE) as $method) {
            $english[$method] = [\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::class, $method]();
        }
        Translator::use(new JsonL10n('es'));
        foreach ($english as $method => $source) {
            $translated = [\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::class, $method]();
            self::assertNotSame($source, $translated, $method . ' has no Spanish text');
            self::assertNotSame('', $translated);
        }
        self::assertStringContainsString(
            "El tablero 'Comercial' pertenece a Paulo",
            \OCA\Mcp\Modules\Deck\Execution\WriteMessages::sharedConfirmation('Comercial', 'Paulo Exemplo'),
        );
    }

    public function testWithoutTranslatorOrWithUnknownLanguageTheMessagesAreEnglish(): void {
        self::assertSame('Invalid data for Deck.', \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorInvalid());
        Translator::use(new JsonL10n('fr'));
        self::assertSame('Invalid data for Deck.', \OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorInvalid());
    }

    public function testMappedExceptionIsTranslated(): void {
        Translator::use(new JsonL10n('pt_BR'));
        self::assertSame('Dados inválidos para o Deck.', DeckErrors::messageFor(new \OCA\Deck\BadRequestException('x')));
        self::assertSame(
            'Não foi possível concluir a operação no Deck.',
            DeckErrors::messageFor(new \RuntimeException('boom')),
        );
    }

    public function testModuleRefusalReachesTheUserTranslated(): void {
        $module = $this->deckModuleFixture(
            $this->nativeDeck(
                $this->createMock(ContainerInterface::class),
                $this->createMock(ITimeFactory::class),
                session: $this->deckSession(),
            ),
            $this->createMock(IAppManager::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(IUserManager::class),
        );
        Translator::use(new JsonL10n('pt_BR'));
        try {
            $module->call('deck_everything', [], 'alice');
            self::fail('Expected the unknown tool to be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Ferramenta do Deck desconhecida.', $e->getMessage());
        }
    }

    public function testDescriptionsAreFixedEnglish(): void {
        $module = $this->deckModuleFixture(
            $this->nativeDeck(
                $this->createMock(ContainerInterface::class),
                $this->createMock(ITimeFactory::class),
                session: $this->deckSession(),
            ),
            $this->createMock(IAppManager::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(IUserManager::class),
        );
        Translator::use(new JsonL10n('pt_BR'));
        $texts = [];
        $definitions = $module->definitions();
        array_walk_recursive(
            $definitions,
            static function ($value, $key) use (&$texts): void {
                if ($key === 'description' && is_string($value)) {
                    $texts[] = $value;
                }
            },
        );
        self::assertNotEmpty($texts);
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/[À-ÿ]|AAAA|\bpara\b|\bdo Deck\b/u', $text);
        }
    }
}
