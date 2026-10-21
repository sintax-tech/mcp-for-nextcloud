<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Notes;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
/**
 * What the user of the Notes module sees comes out in the language of the account; what the model reads
 * (the tool descriptions) stays English in every language.
 */
final class NotesTranslationTest extends TestCase
{

    public function testPortugueseUserGetsTheSameTextsAsBefore(): void
    {
        Translator::use(new JsonL10n('pt_BR'));
        self::assertSame('Nota excede o limite de leitura de ' . \OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES . ' bytes.', \OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLargeForReading(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES));
        self::assertSame('Nota excede o limite de ' . \OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES . ' bytes.', \OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLarge(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES));
        self::assertSame('Já existe uma nota com este título nesta categoria.', \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory());
        self::assertSame('Já existe uma nota com este título na categoria de destino.', \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInTargetCategory());
        self::assertSame('Já existem notas demais com este título.', \OCA\Mcp\Modules\Notes\Storage\NamingMessages::tooManyNotesWithSameTitle());
        self::assertSame('Exclusão bloqueada: a lixeira (files_trashbin) não está ativa para esta nota, então ela não seria recuperável.', \OCA\Mcp\Modules\Notes\Execution\WriteMessages::notRecoverable());
    }

    public function testSpanishUserGetsSpanishTexts(): void
    {
        $english = [\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLargeForReading(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES), \OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLarge(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES), \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory(), \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInTargetCategory(), \OCA\Mcp\Modules\Notes\Storage\NamingMessages::tooManyNotesWithSameTitle(), \OCA\Mcp\Modules\Notes\Execution\WriteMessages::notRecoverable()];
        Translator::use(new JsonL10n('es'));
        $translated = [\OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLargeForReading(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES), \OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLarge(\OCA\Mcp\Modules\Notes\Content\NoteContent::MAX_BYTES), \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory(), \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInTargetCategory(), \OCA\Mcp\Modules\Notes\Storage\NamingMessages::tooManyNotesWithSameTitle(), \OCA\Mcp\Modules\Notes\Execution\WriteMessages::notRecoverable()];
        foreach ($translated as $index => $text) {
            self::assertNotSame($english[$index], $text);
            self::assertNotSame('', $text);
        }
        self::assertSame('Ya existe una nota con este título en esta categoría.', $translated[2]);
    }

    public function testWithoutTranslatorOrWithUnknownLanguageTheMessagesAreEnglish(): void
    {
        self::assertSame('A note with this title already exists in this category.', \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory());
        Translator::use(new JsonL10n('fr'));
        self::assertSame('A note with this title already exists in this category.', \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory());
    }

    /** The descriptions are read by the model, so they never change with the language of the account. */

    public function testDescriptionsAreFixedEnglish(): void
    {
        $module = \OCA\Mcp\Tests\Support\Notes\NotesTestFactory::module($this, [\OCA\Mcp\Modules\Notes\Storage\NotesFolders::class => $this->createMock(NotesFolders::class), \OCA\Mcp\Modules\Notes\Storage\NoteLookup::class => new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(null), \OCA\Mcp\Modules\Notes\Storage\NoteMetadata::class => new \OCA\Mcp\Modules\Notes\Storage\NoteMetadata(null), \OCA\Mcp\Modules\Notes\Storage\NoteNames::class => new \OCA\Mcp\Modules\Notes\Storage\NoteNames(), \OCA\Mcp\Modules\Notes\Content\NoteContent::class => new \OCA\Mcp\Modules\Notes\Content\NoteContent(), \OCP\App\IAppManager::class => $this->createMock(IAppManager::class), \OCP\IUserManager::class => $this->createMock(IUserManager::class), \OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class => $this->createMock(SharedWriteGuard::class), \OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class => $this->createMock(NodeAccessInfo::class), \OCA\Mcp\Access\Visibility\VisibilityGuard::class => null, \OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite::class => null]);
        Translator::use(new JsonL10n('pt_BR'));
        $definitions = $module->definitions();
        $texts = [];
        array_walk_recursive($definitions, static function ($value, $key) use (&$texts): void {
            if ($key === 'description' && is_string($value)) {
                $texts[] = $value;
            }
        });
        self::assertCount(27, $texts);
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/[À-ÿ]/u', $text);
        }
        self::assertSame('Lists the notes (Notes app) of the user.', $definitions[0]['description']);
        self::assertSame('Id of the note.', $definitions[2]['inputSchema']['properties']['id']['description']);
    }

    protected function tearDown(): void
    {
        Translator::reset();
    }
}
