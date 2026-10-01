<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Notes\NotesMessages;
use OCA\Mcp\Tools\ToolPresentation;
use PHPUnit\Framework\TestCase;

/**
 * The languages the app ships, and what happens when the account has none of them.
 *
 * L10nTest checks pt_BR key by key against the source; this file keeps every other language file in sync
 * with it and pins the fallback: with no translator, or with a language that has no file, the English
 * source text comes out unchanged, which is what tests, CLI code and an English account get.
 */
final class L10nLanguagesTest extends TestCase {
    /** App root: this file lives in tests/Unit, two levels below it. */
    private const APP_ROOT = __DIR__ . '/../..';

    protected function tearDown(): void {
        Translator::reset();
    }

    /**
     * @param string $language language code of a file inside l10n/
     * @return array<string, string|list<string>> translations of that language
     */
    private function translations(string $language): array {
        $decoded = json_decode((string)file_get_contents(self::APP_ROOT . '/l10n/' . $language . '.json'), true);
        $this->assertIsArray($decoded, 'l10n/' . $language . '.json must be valid JSON');
        $this->assertArrayHasKey('translations', $decoded);
        $this->assertArrayHasKey('pluralForm', $decoded);
        $this->assertIsString($decoded['pluralForm']);
        $this->assertNotSame('', $decoded['pluralForm']);
        return $decoded['translations'];
    }

    /** Every shipped language is a valid file with non-empty texts, and its .js twin lists the same keys. */
    public function testEveryShippedLanguageFileIsValidAndHasAMatchingJavaScriptFile(): void {
        $files = glob(self::APP_ROOT . '/l10n/*.json') ?: [];
        $this->assertNotEmpty($files, 'the app ships at least one language');
        $languages = array_map(static fn (string $file): string => basename($file, '.json'), $files);
        sort($languages);
        $this->assertSame(['es', 'pt_BR'], $languages, 'English is the source text, not a file');

        foreach ($languages as $language) {
            $translations = $this->translations($language);
            $this->assertNotSame([], $translations, $language . ' must not be empty');
            foreach ($translations as $key => $value) {
                $valid = is_string($value) && $value !== '' || is_array($value) && $value !== [];
                $this->assertTrue($valid, $language . ': translation of "' . $key . '" must be a non-empty string or a plural array');
            }

            $js = (string)file_get_contents(self::APP_ROOT . '/l10n/' . $language . '.js');
            $this->assertStringContainsString('OC.L10N.register', $js, $language . '.js');
            $this->assertStringContainsString('"mcp"', $js, $language . '.js must register the "mcp" app');
            preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:\s*(?:"(?:[^"\\\\]|\\\\.)*"|\[[^\]]*\])\s*,?\s*$/m', $js, $matches);
            $fromJs = $matches[1];
            $fromJson = array_keys($translations);
            sort($fromJs);
            sort($fromJson);
            $this->assertSame($fromJson, $fromJs, 'l10n/' . $language . '.js and .json must list the same keys');
        }
    }

    /** A language can only be complete while it carries every key the Portuguese file carries. */
    public function testSpanishHasExactlyTheKeysOfPortuguese(): void {
        $portuguese = array_keys($this->translations('pt_BR'));
        $spanish = array_keys($this->translations('es'));
        sort($portuguese);
        sort($spanish);
        $this->assertSame($portuguese, $spanish, 'es.json and pt_BR.json must list the same keys');
    }

    /** Without a translator the English source text comes out as it is, placeholders resolved. */
    public function testWithoutTranslatorTheEnglishSourceComesOut(): void {
        Translator::reset();
        self::assertSame('Search files', ToolPresentation::title('files_search'));
        self::assertSame('Note exceeds the limit of 10 bytes.', NotesMessages::noteTooLarge(10));
        Translator::use(new JsonL10n('pt_BR'));
        self::assertSame('Buscar arquivos', ToolPresentation::title('files_search'));
        Translator::reset();
        self::assertSame('Search files', ToolPresentation::title('files_search'));
    }

    /** A language the app does not ship falls back to English instead of showing nothing or a key. */
    public function testALanguageWithoutAFileFallsBackToEnglish(): void {
        Translator::use(new JsonL10n('de'));
        self::assertSame('Search files', ToolPresentation::title('files_search'));
        self::assertSame('Note exceeds the limit of 10 bytes.', NotesMessages::noteTooLarge(10));
        self::assertSame('A note with this title already exists in this category.', NotesMessages::titleExistsInCategory());
    }
}
