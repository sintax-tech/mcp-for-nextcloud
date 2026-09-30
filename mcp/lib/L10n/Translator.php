<?php
declare(strict_types=1);

namespace OCA\Mcp\L10n;

use OCP\IL10N;

/**
 * Per-request translation facade used by the tool modules.
 *
 * A PHP request serves a single user, so the translator is configured once after authentication and the messages,
 * which are static methods used from many places, stay free of constructor plumbing. Without {@see use()} the source
 * text in English comes out unchanged, which is what tests and CLI code get. The source text must always be a literal
 * in the call so the Nextcloud translation tool can extract it.
 */
final class Translator {
    private static ?IL10N $l10n = null;

    private function __construct() {
    }

    /**
     * Makes the given translator the one of the current request.
     *
     * @param IL10N $l10n translator of the authenticated user
     */
    public static function use(IL10N $l10n): void {
        self::$l10n = $l10n;
    }

    /** Forgets the translator, so the next call returns the English source text again. */
    public static function reset(): void {
        self::$l10n = null;
    }

    /**
     * Translates a text.
     *
     * @param string $text English source text, a literal
     * @param array<int, mixed> $params values for the %s placeholders
     */
    public static function t(string $text, array $params = []): string {
        return self::$l10n === null ? vsprintf($text, $params) : self::$l10n->t($text, $params);
    }

    /**
     * Translates a text with a plural form.
     *
     * @param string $singular English singular source text, a literal
     * @param string $plural English plural source text, a literal
     * @param int $count number that selects the form and replaces %n
     * @param array<int, mixed> $params values for the %s placeholders
     */
    public static function n(string $singular, string $plural, int $count, array $params = []): string {
        if (self::$l10n !== null) {
            return self::$l10n->n($singular, $plural, $count, $params);
        }
        return vsprintf(str_replace('%n', (string)$count, $count === 1 ? $singular : $plural), $params);
    }
}
