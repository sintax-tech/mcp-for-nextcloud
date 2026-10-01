<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The texts of the app are written in English and go through {@see \OCA\Mcp\L10n\Translator}, so a string
 * literal of lib/ written straight in Portuguese is a text every user of another language would still read
 * in Portuguese: the translation files cannot reach a string the code never asks them for.
 *
 * The allowlist is exact, and both directions are checked: a literal outside it fails the test, and an
 * entry inside it that no longer holds any accented literal fails it too, so translating a module forces
 * its entry out of the list instead of leaving a hole behind. Comments and docblocks are skipped, because
 * the author writes those for the reader of the code.
 */
final class L10nSourceTextTest extends TestCase {
    /** App root: this file lives in tests/Unit, two levels below it. */
    private const APP_ROOT = __DIR__ . '/../..';

    /**
     * Modules still on their original texts, path prefixes relative to the app root.
     *
     * @var list<string>
     */
    private const ALLOWED = [];

    /**
     * Every file of lib/ holding an accented string literal, which is what a Portuguese text looks like.
     *
     * @return array<string, true> relative path of each file, as the set it belongs to
     */
    private function accentedFiles(): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::APP_ROOT . '/lib', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'lib/' . substr($file->getPathname(), strlen(self::APP_ROOT . '/lib/'));
            foreach (token_get_all((string)file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (!in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                if (preg_match('/[À-ÿ]/u', $token[1]) === 1) {
                    $files[$relative] = true;
                    break;
                }
            }
        }
        ksort($files);
        return $files;
    }

    /** @return bool whether $file is covered by an entry of the allowlist */
    private function allowed(string $file): bool {
        foreach (self::ALLOWED as $prefix) {
            if (str_contains($file, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function testNoLiteralOfLibIsWrittenInTheLanguageOfTheUser(): void {
        $accented = array_keys($this->accentedFiles());
        if ($accented === []) {
            $this->assertSame([], $accented);
            return;
        }
        foreach ($accented as $file) {
            $this->assertTrue(
                $this->allowed($file),
                $file . ': a user-visible text must go through Translator::t() with an English source literal',
            );
        }
    }

    public function testEveryEntryOfTheAllowlistStillHoldsAnUntranslatedText(): void {
        if (self::ALLOWED === []) {
            $this->assertSame([], self::ALLOWED);
            return;
        }
        $accented = $this->accentedFiles();
        foreach (self::ALLOWED as $prefix) {
            $pending = array_filter(array_keys($accented), fn (string $file): bool => str_contains($file, $prefix));
            $this->assertNotEmpty(
                $pending,
                $prefix . ' is fully translated: drop it from the allowlist of this test',
            );
        }
    }
}
