<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Access\Grants\GrantPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Keeps l10n/pt_BR.json in sync with the translatable strings of the app.
 *
 * Every file of templates/, recursive src/ and generated js/ is scanned for literal translation calls: $l->t('…')
 * and $l->n('…', '…') in PHP templates, t('mcp', '…') and n('mcp', '…', '…') in JS.
 * The extracted set must equal the key set of l10n/pt_BR.json in both directions, so a
 * string added later (the admin matrix, for instance) fails until it is translated and a
 * key left behind fails until it is removed. l10n/pt_BR.js must carry the same keys,
 * because that is the file the browser actually loads.
 *
 * Module and operation IDs in GrantPolicy::CATALOG remain reserved translation keys.
 * Check them explicitly rather than relying on a comment resembling a dynamic call.
 */
final class L10nTest extends TestCase {
    /** App root: this file lives in tests/Unit, two levels below it. */
    private const APP_ROOT = __DIR__ . '/../..';

    /** Language of the translation file under test. */
    private const LANGUAGE = 'pt_BR';

    /** @return string path of a file inside the app root */
    private function path(string $relative): string {
        return self::APP_ROOT . '/' . $relative;
    }

    /** @return array<string, mixed> decoded contents of the pt_BR translation file */
    private function translations(): array {
        $decoded = json_decode((string)file_get_contents($this->path('l10n/' . self::LANGUAGE . '.json')), true);
        $this->assertIsArray($decoded, 'l10n/' . self::LANGUAGE . '.json must be valid JSON');
        $this->assertArrayHasKey('translations', $decoded);
        $this->assertArrayHasKey('pluralForm', $decoded);
        $this->assertIsArray($decoded['translations']);
        return $decoded;
    }

    /** The JSON must be parseable, carry the pt_BR plural rule and no duplicated key. */
    public function testTranslationFileIsValid(): void {
        $decoded = $this->translations();
        $this->assertNotSame([], $decoded['translations'], 'the pt_BR translation must not be empty');
        $this->assertIsString($decoded['pluralForm']);
        $this->assertMatchesRegularExpression('/^nplurals=3;/', $decoded['pluralForm'], 'pt_BR plural rule');
        foreach ($decoded['translations'] as $key => $value) {
            $valid = is_string($value) && $value !== '' || is_array($value) && $value !== [];
            $this->assertTrue($valid, 'translation of "' . $key . '" must be a non-empty string or a plural array');
        }
        $raw = (string)file_get_contents($this->path('l10n/' . self::LANGUAGE . '.json'));
        preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/m', $raw, $matches);
        $this->assertSame(count($decoded['translations']), count($matches[0]), 'pt_BR.json must not repeat a key');
    }

    /** Strings referenced by templates/, recursive src/ and generated js/ and keys of pt_BR.json must be the same set. */
    public function testCodeAndJsonKeysMatch(): void {
        $expected = $this->codeKeys();
        $actual = array_keys($this->translations()['translations']);
        $untranslated = array_values(array_diff($expected, $actual));
        $unused = array_values(array_diff($actual, $expected));
        $this->assertSame([], $untranslated, 'strings used in templates/, src/ or js/ without a pt_BR translation');
        $this->assertSame([], $unused, 'pt_BR keys that templates/, recursive src/ and generated js/ no longer use');
    }

    /** The JS translation file is what OC.L10N loads, so it must mirror the JSON. */
    public function testJavaScriptFileRegistersTheSameKeys(): void {
        $js = (string)file_get_contents($this->path('l10n/' . self::LANGUAGE . '.js'));
        $this->assertStringContainsString('OC.L10N.register', $js);
        $this->assertStringContainsString('"mcp"', $js, 'the JS file must register the "mcp" app');
        preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:\s*(?:"(?:[^"\\\\]|\\\\.)*"|\[[^\]]*\])\s*,?\s*$/m', $js, $matches);
        $fromJs = $matches[1];
        $fromJson = array_keys($this->translations()['translations']);
        sort($fromJs);
        sort($fromJson);
        $this->assertSame($fromJson, $fromJs, 'l10n/' . self::LANGUAGE . '.js and .json must list the same keys');
    }

    /** Every locale supplies every plural form declared by its own rule, in JSON and JS. */
    public function testAllLocalesSupplyEveryDeclaredPluralForm(): void {
        $files = glob($this->path('l10n/*.json')) ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $decoded = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, preg_match('/\bnplurals\s*=\s*(\d+)\s*;/', $decoded['pluralForm'], $rule), $locale);
            $forms = (int)$rule[1];
            $this->assertGreaterThan(0, $forms, $locale);
            $js = (string)file_get_contents($this->path('l10n/' . $locale . '.js'));
            $this->assertStringContainsString(json_encode($decoded['pluralForm'], JSON_THROW_ON_ERROR), $js, "$locale JS and JSON plural rules must match");
            $this->assertSame(1, preg_match('/\{.*\}/s', $js, $bundle), $locale);
            $fromJs = json_decode($bundle[0], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($decoded['translations'], $fromJs, "$locale JS and JSON must match");
            foreach ($decoded['translations'] as $key => $value) {
                if (!str_starts_with($key, '_') || !str_contains($key, '_::_')) {
                    continue;
                }
                $this->assertIsArray($value, "$locale: $key");
                $this->assertCount($forms, $value, "$locale: $key must supply $forms plural forms");
                foreach ($value as $translation) {
                    $this->assertIsString($translation, "$locale: $key");
                    $this->assertNotSame('', $translation, "$locale: $key");
                }
            }
        }
    }

    /** Plural extraction uses the same separator as Nextcloud's translation registry. */
    public function testPluralKeysUseTheNextcloudRegistrySeparator(): void {
        $this->assertSame(['_One group_::_Many groups_'],
            $this->literalKeys("n('mcp', 'One group', 'Many groups', 2)", 'admin.js'));
        $this->assertSame(['_One group_::_Many groups_'],
            $this->literalKeys("\$l->n('One group', 'Many groups', 2)", 'admin.php'));
    }

    /**
     * Collects the translation keys referenced by templates/, recursive src/ and generated js/.
     *
     * @return list<string> literal source strings and the reserved catalog IDs
     */
    private function codeKeys(): array {
        $keys = [];
        foreach (['templates', 'src', 'js'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path($dir), \FilesystemIterator::SKIP_DOTS)) as $entry) {
                $file = $entry->getPathname();
                if (!is_file($file) || !in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', 'js'], true)) {
                    continue;
                }
                $source = (string)file_get_contents($file);
                foreach ($this->literalKeys($source, $file) as $key) {
                    $keys[] = $key;
                }
            }
        }
        return array_values(array_unique(array_merge($keys, $this->libraryKeys(), $this->catalogKeys())));
    }

    /**
     * Literal source texts of the Translator::t() and Translator::n() calls under lib/.
     *
     * @return list<string> keys in the format of the translation file
     */
    private function libraryKeys(): array {
        $keys = [];
        foreach ($this->libraryFiles() as $file) {
            $source = (string)file_get_contents($file);
            $this->assertSame([], $this->nonLiteralCalls($source), $file . ': the source text of Translator::t()/n() must be a literal');
            foreach (["/Translator::t\\s*\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*[,)]/s", '/Translator::t\\s*\\(\\s*"((?:[^"\\\\]|\\\\.)*)"\\s*[,)]/s'] as $pattern) {
                preg_match_all($pattern, $source, $matches);
                $keys = array_merge($keys, array_map($this->unescape(...), $matches[1]));
            }
            foreach (["/Translator::n\\s*\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,/s", '/Translator::n\\s*\\(\\s*"((?:[^"\\\\]|\\\\.)*)"\\s*,\\s*"((?:[^"\\\\]|\\\\.)*)"\\s*,/s'] as $pattern) {
                preg_match_all($pattern, $source, $matches);
                foreach ($matches[1] as $i => $singular) {
                    $keys[] = '_' . $this->unescape($singular) . '_::_' . $this->unescape($matches[2][$i]) . '_';
                }
            }
        }
        return array_values(array_unique($keys));
    }

    /** @return list<string> every PHP file under lib/ */
    private function libraryFiles(): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path('lib'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<string> Translator calls whose first argument does not start with a quoted literal or continues after it */
    private function nonLiteralCalls(string $source): array {
        $bad = [];
        preg_match_all("/Translator::([tn])\\s*\\((.{0,4000})/s", $source, $calls, PREG_SET_ORDER);
        foreach ($calls as $call) {
            $literal = "/^\\s*(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")\\s*[,)]/s";
            if (!preg_match($literal, $call[2])) {
                $bad[] = $call[0];
            }
        }
        return $bad;
    }

    /** @return list<string> keys of one source file */
    private function literalKeys(string $source, string $file): array {
        $keys = [];
        if (str_ends_with($file, '.php')) {
            // $l->t('literal') and $l->t("literal")
            foreach (["/\\\$l->t\\s*\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/s", '/\$l->t\s*\(\s*"((?:[^"\\\\]|\\\\.)*)"/s'] as $pattern) {
                preg_match_all($pattern, $source, $matches);
                $keys = array_merge($keys, array_map($this->unescape(...), $matches[1]));
            }
            // $l->n('singular', 'plural', …) is stored as "_singular_::_plural_"
            foreach (["/\\\$l->n\\s*\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/s", '/\$l->n\s*\(\s*"((?:[^"\\\\]|\\\\.)*)"\s*,\s*"((?:[^"\\\\]|\\\\.)*)"/s'] as $pattern) {
                preg_match_all($pattern, $source, $matches);
                foreach ($matches[1] as $i => $singular) {
                    $keys[] = '_' . $this->unescape($singular) . '_::_' . $this->unescape($matches[2][$i]) . '_';
                }
            }
            return $keys;
        }
        // t('mcp', 'literal') and n('mcp', 'singular', 'plural', …)
        foreach (["/(?<![A-Za-z0-9_\$.])t\\s*\\(\\s*'mcp'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/s", '/(?<![A-Za-z0-9_$.])t\s*\(\s*"mcp"\s*,\s*"((?:[^"\\\\]|\\\\.)*)"/s'] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            $keys = array_merge($keys, array_map($this->unescape(...), $matches[1]));
        }
        foreach (["/(?<![A-Za-z0-9_\$.])n\\s*\\(\\s*'mcp'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/s", '/(?<![A-Za-z0-9_$.])n\s*\(\s*"mcp"\s*,\s*"((?:[^"\\\\]|\\\\.)*)"\s*,\s*"((?:[^"\\\\]|\\\\.)*)"/s'] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] as $i => $singular) {
                $keys[] = '_' . $this->unescape($singular) . '_::_' . $this->unescape($matches[2][$i]) . '_';
            }
        }
        return $keys;
    }

    /**
     * Module and operation names rendered by the admin grant table.
     *
     * @return list<string>
     */
    private function catalogKeys(): array {
        $keys = array_keys(GrantPolicy::CATALOG);
        foreach (GrantPolicy::CATALOG as $operations) {
            $keys = array_merge($keys, $operations);
        }
        return array_values(array_unique($keys));
    }

    /** @return string the PHP/JS escape sequences of a source string, resolved */
    private function unescape(string $value): string {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
    }
}
