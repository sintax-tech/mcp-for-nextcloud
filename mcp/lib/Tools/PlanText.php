<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * The single rule that makes text written by somebody else inert inside a confirmation plan.
 *
 * Titles, notes, names and messages come from the model, from the person and from other accounts, so none of
 * them may be read as Markdown or HTML, nor open a new line of the plan: a forged heading or footer would
 * pass for text the server wrote. Every renderer builds its lines with these helpers and never with a raw
 * value. Stateless on purpose: the renderers are shared services.
 */
final class PlanText {
    /** Default cut for one inline value. */
    public const INLINE_MAX = 200;
    /** Characters the Markdown inline syntax gives a meaning to, and the HTML and entity openers. */
    private const SPECIAL = ['\\', '`', '*', '_', '[', ']', '<', '>', '#', '|', '~', '&'];

    /**
     * One line of plain text, not escaped: for strings that travel as data and are escaped once, when a plan is rendered.
     *
     * @param string $text user, model or third-party text
     * @param int $max characters kept; longer text ends with an ellipsis
     * @return string the text on one line, without control characters
     */
    public static function flat(string $text, int $max = self::INLINE_MAX): string {
        $clean = self::collapse($text);
        return mb_strlen($clean) > $max ? rtrim(mb_substr($clean, 0, $max)) . '…' : $clean;
    }

    /**
     * A value the model must repeat exactly (a path, an etag) as inline code: nothing inside is read as Markdown.
     *
     * @param string $text value to show
     * @param int $max characters kept; a value is never cut silently, so callers pass a generous limit
     * @return string the value in backticks, or an empty string when nothing is left of it
     */
    public static function code(string $text, int $max = 500): string {
        $clean = str_replace('`', "'", self::flat($text, $max));
        return $clean === '' ? '' : '`' . $clean . '`';
    }

    /**
     * One line of inert text: no line break survives, nothing is read as Markdown, HTML or a link.
     *
     * @param string $text user, model or third-party text
     * @param int $max characters kept before escaping; longer text ends with an ellipsis
     * @return string text safe to put inside a line of the plan
     */
    public static function inline(string $text, int $max = self::INLINE_MAX): string {
        $clean = self::collapse($text);
        $cut = mb_strlen($clean) > $max;
        return self::escape($cut ? rtrim(mb_substr($clean, 0, $max)) : $clean) . ($cut ? '…' : '');
    }

    /**
     * @param string $text user, model or third-party text
     * @param int $max characters kept before escaping
     * @return string the text in bold, or an empty string when nothing is left of it
     */
    public static function strong(string $text, int $max = self::INLINE_MAX): string {
        $inline = self::inline($text, $max);
        return $inline === '' ? '' : '**' . $inline . '**';
    }

    /**
     * @param string $text user, model or third-party text
     * @param int $max characters kept before escaping
     * @return string the text in italics, or an empty string when nothing is left of it
     */
    public static function em(string $text, int $max = self::INLINE_MAX): string {
        $inline = self::inline($text, $max);
        return $inline === '' ? '' : '*' . $inline . '*';
    }

    /**
     * A block quote that keeps the line breaks of the text but lets no line leave the block.
     *
     * @param string $text message or paragraph typed by somebody else
     * @param int $max characters kept before escaping; longer text ends with an ellipsis
     * @return string quoted lines, or an empty string when there is no text
     */
    public static function quote(string $text, int $max = self::INLINE_MAX): string {
        $text = trim(preg_replace('/\r\n|[\r\x{85}\x{2028}\x{2029}]/u', "\n", mb_scrub($text, 'UTF-8')) ?? '');
        if ($text === '') {
            return '';
        }
        $cut = mb_strlen($text) > $max;
        if ($cut) {
            $text = mb_substr($text, 0, $max);
        }
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = self::collapse($line);
            $lines[] = rtrim('> ' . self::escape($line));
        }
        if ($cut) {
            $lines[count($lines) - 1] .= '…';
        }
        return implode("\n", $lines);
    }

    /**
     * @param string $text any text, possibly with invalid UTF-8
     * @return string the text on one line: every run of line breaks, tabs, spaces and control characters is one space
     */
    private static function collapse(string $text): string {
        return trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', mb_scrub($text, 'UTF-8')) ?? '');
    }

    /**
     * @param string $line single line, already free of control characters
     * @return string the line with Markdown characters, bare URLs and a leading list marker neutralized
     */
    private static function escape(string $line): string {
        $out = '';
        foreach (mb_str_split($line) as $char) {
            $out .= in_array($char, self::SPECIAL, true) ? '\\' . $char : $char;
        }
        // "(" matters only right after "]", where it would open a link target; elsewhere "(pedro)" reads better as it is.
        $out = str_replace('\\](', '\\]\\(', $out);
        // A bare address becomes a link in most renderers: break the scheme separator and the www form.
        $out = preg_replace('#:(?=//)#', '\\:', $out) ?? $out;
        $out = preg_replace('/\b(www)\./i', '$1\\.', $out) ?? $out;
        // Only the start of a line can open a list: "- x", "+ x", "1. x", "1) x".
        return self::escapeLeadingMarker($out);
    }

    /**
     * @param string $line escaped line
     * @return string line whose leading list marker, if any, is backslash-escaped
     */
    private static function escapeLeadingMarker(string $line): string {
        if (preg_match('/^([-+])(?= )/', $line) === 1) {
            return '\\' . $line;
        }
        return preg_replace('/^(\d{1,9})([.)])(?= )/', '$1\\\\$2', $line, 1) ?? $line;
    }
}
