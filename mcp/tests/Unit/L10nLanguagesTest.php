<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation;
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
        $this->assertSame(['de', 'es', 'pt_BR'], $languages, 'English is the source text, not a file');

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

            $this->assertMatchesRegularExpression(
                '/\},\s*"((?:nplurals=[^"]+))"\s*\);\s*$/s',
                $js,
                $language . '.js must have valid OC.L10N.register syntax with the pluralForm string as 3rd argument',
            );
            preg_match('/\},\s*"((?:nplurals=[^"]+))"\s*\);\s*$/s', $js, $jsPluralMatch);
            $decoded = json_decode((string)file_get_contents(self::APP_ROOT . '/l10n/' . $language . '.json'), true);
            $this->assertSame($decoded['pluralForm'], $jsPluralMatch[1] ?? '', $language . '.js pluralForm must match .json pluralForm');
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

    public function testGermanHasTheKeysAndPlaceholdersOfPortuguese(): void {
        $portuguese = $this->translations('pt_BR');
        $german = $this->translations('de');
        $this->assertSame([], array_values(array_diff(array_keys($german), array_keys($portuguese))),
            'de.json must not list a key that pt_BR.json does not have');
        $this->assertEqualsCanonicalizing(array_keys($portuguese), array_keys($german), 'Every runtime catalog has the same source keys');
        $placeholders = static function (string $text): array {
            preg_match_all('/%(?:\\d+\\$)?[sdn]|\{[^}]*\}/', $text, $found);
            sort($found[0]);
            return $found[0];
        };
        foreach ($german as $key => $value) {
            if (is_array($value)) {
                $this->assertCount(2, $value, 'de: "' . $key . '" needs the two German plural forms');
            }
            foreach ((array)$value as $form) {
                $this->assertSame(
                    array_values(array_unique($placeholders($key))),
                    array_values(array_unique($placeholders($form))),
                    'de: placeholders of "' . $key . '" must match the source',
                );
            }
        }
        $decoded = json_decode((string)file_get_contents(self::APP_ROOT . '/l10n/de.json'), true);
        $this->assertSame('nplurals=2; plural=(n != 1);', $decoded['pluralForm']);
    }

    /** Without a translator the English source text comes out as it is, placeholders resolved. */
    public function testWithoutTranslatorTheEnglishSourceComesOut(): void {
        Translator::reset();
        self::assertSame('Search files', ToolPresentation::title('files_search'));
        self::assertSame('Note exceeds the limit of 10 bytes.', \OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLarge(10));
        Translator::use(new JsonL10n('pt_BR'));
        self::assertSame('Buscar arquivos', ToolPresentation::title('files_search'));
        Translator::reset();
        self::assertSame('Search files', ToolPresentation::title('files_search'));
    }

    /** A language the app does not ship falls back to English instead of showing nothing or a key. */
    public function testALanguageWithoutAFileFallsBackToEnglish(): void {
        Translator::use(new JsonL10n('fr'));
        self::assertSame('Search files', ToolPresentation::title('files_search'));
        self::assertSame('Note exceeds the limit of 10 bytes.', \OCA\Mcp\Modules\Notes\Content\ContentMessages::noteTooLarge(10));
        self::assertSame('A note with this title already exists in this category.', \OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInCategory());
    }
}
