<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Notes\NotesMessages;
use OCA\Mcp\Tools\Notes\NotesModule;
use OCA\Mcp\Tools\Notes\NotesRepository;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * What the user of the Notes module sees comes out in the language of the account; what the model reads
 * (the tool descriptions) stays English in every language.
 */
final class NotesTranslationTest extends TestCase {
    public function testPortugueseUserGetsTheSameTextsAsBefore(): void {
        Translator::use(new JsonL10n('pt_BR'));
        self::assertSame(
            'Nota excede o limite de leitura de ' . NotesModule::MAX_BYTES . ' bytes.',
            NotesMessages::noteTooLargeForReading(NotesModule::MAX_BYTES),
        );
        self::assertSame(
            'Nota excede o limite de ' . NotesModule::MAX_BYTES . ' bytes.',
            NotesMessages::noteTooLarge(NotesModule::MAX_BYTES),
        );
        self::assertSame('Já existe uma nota com este título nesta categoria.', NotesMessages::titleExistsInCategory());
        self::assertSame('Já existe uma nota com este título na categoria de destino.', NotesMessages::titleExistsInTargetCategory());
        self::assertSame('Já existem notas demais com este título.', NotesMessages::tooManyNotesWithSameTitle());
        self::assertSame(
            'Exclusão bloqueada: a lixeira (files_trashbin) não está ativa para esta nota, então ela não seria recuperável.',
            NotesMessages::notRecoverable(),
        );
    }

    public function testSpanishUserGetsSpanishTexts(): void {
        $english = [
            NotesMessages::noteTooLargeForReading(NotesModule::MAX_BYTES),
            NotesMessages::noteTooLarge(NotesModule::MAX_BYTES),
            NotesMessages::titleExistsInCategory(),
            NotesMessages::titleExistsInTargetCategory(),
            NotesMessages::tooManyNotesWithSameTitle(),
            NotesMessages::notRecoverable(),
        ];
        Translator::use(new JsonL10n('es'));
        $translated = [
            NotesMessages::noteTooLargeForReading(NotesModule::MAX_BYTES),
            NotesMessages::noteTooLarge(NotesModule::MAX_BYTES),
            NotesMessages::titleExistsInCategory(),
            NotesMessages::titleExistsInTargetCategory(),
            NotesMessages::tooManyNotesWithSameTitle(),
            NotesMessages::notRecoverable(),
        ];
        foreach ($translated as $index => $text) {
            self::assertNotSame($english[$index], $text);
            self::assertNotSame('', $text);
        }
        self::assertSame('Ya existe una nota con este título en esta categoría.', $translated[2]);
    }

    public function testWithoutTranslatorOrWithUnknownLanguageTheMessagesAreEnglish(): void {
        self::assertSame('A note with this title already exists in this category.', NotesMessages::titleExistsInCategory());
        Translator::use(new JsonL10n('de'));
        self::assertSame('A note with this title already exists in this category.', NotesMessages::titleExistsInCategory());
    }

    /** The descriptions are read by the model, so they never change with the language of the account. */
    public function testDescriptionsAreFixedEnglish(): void {
        $module = new NotesModule(
            $this->createMock(NotesRepository::class),
            $this->createMock(IAppManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(SharedWriteGuard::class),
            $this->createMock(NodeAccessInfo::class),
        );
        Translator::use(new JsonL10n('pt_BR'));
        $definitions = $module->definitions();
        $texts = [];
        array_walk_recursive($definitions, static function ($value, $key) use (&$texts): void {
            if ($key === 'description' && is_string($value)) {
                $texts[] = $value;
            }
        });
        self::assertCount(26, $texts);
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/[À-ÿ]/u', $text);
        }
        self::assertSame(NotesMessages::TOOL_LIST_DESCRIPTION, $definitions[0]['description']);
        self::assertSame(NotesMessages::PARAM_ID, $definitions[2]['inputSchema']['properties']['id']['description']);
    }

    protected function tearDown(): void {
        Translator::reset();
    }
}
