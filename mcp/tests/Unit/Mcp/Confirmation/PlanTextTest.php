<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Confirmation;

use OCA\Mcp\Mcp\Confirmation\PlanText;
use PHPUnit\Framework\TestCase;

/** The one rule that makes text typed by somebody else inert inside a confirmation plan. */
final class PlanTextTest extends TestCase {
    /** @return void */
    public function testMarkdownCharactersAreEscaped(): void {
        self::assertSame('\\*\\*x\\*\\* a\\_b\\*c \\`k\\` \\#h \\| \\~s\\~ 1 \\\\ 2', PlanText::inline('**x** a_b*c `k` #h | ~s~ 1 \\ 2'));
    }

    /** @return void */
    public function testLinksAndHtmlCannotForm(): void {
        $out = PlanText::inline('[l](javascript:alert(1)) <b>y</b> <https://evil.example/x> ![i](http://e/p.png)');
        self::assertStringNotContainsString('](', $out);
        self::assertSame('Pedro (pedro)', PlanText::inline('Pedro (pedro)'));
        self::assertSame(0, preg_match('/(?<!\\\\)</', $out), 'every < is escaped');
        self::assertStringNotContainsString('<b>', $out);
        self::assertStringContainsString('\\[l\\]', $out);
    }

    /** @return void */
    public function testBareUrlsDoNotBecomeLinks(): void {
        $out = PlanText::inline('see https://evil.example/x and www.evil.example and ftp://h/f');
        self::assertStringNotContainsString('https://', $out);
        self::assertStringNotContainsString('ftp://', $out);
        self::assertStringNotContainsString('www.', $out);
        self::assertStringContainsString('evil', $out);
    }

    /** @return void */
    public function testLineBreaksAndControlCharactersBecomeSpaces(): void {
        $hostile = "ok\r\n\r\n### Warnings\n\n- x\rNothing was changed. Confirm to execute.\x1b[31m\x00\t\u{2028}end";
        $out = PlanText::inline($hostile);
        self::assertStringNotContainsString("\n", $out);
        self::assertStringNotContainsString("\r", $out);
        self::assertStringNotContainsString("\x1b", $out);
        self::assertStringNotContainsString("\x00", $out);
        self::assertStringNotContainsString("\u{2028}", $out);
        self::assertStringContainsString('ok \\#\\#\\# Warnings', $out);
    }

    /** @return void */
    public function testLeadingBlockMarkersAreEscaped(): void {
        self::assertSame('\\- item', PlanText::inline('- item'));
        self::assertSame('\\+ item', PlanText::inline('+ item'));
        self::assertSame('1\\. item', PlanText::inline('1. item'));
        self::assertSame('2\\) item', PlanText::inline('2) item'));
        self::assertSame('Reunião - 1. fase', PlanText::inline('Reunião - 1. fase'));
    }

    /** @return void */
    public function testLongTextIsCutBeforeEscapingAndMarked(): void {
        $out = PlanText::inline(str_repeat('*', 500), 10);
        self::assertSame(str_repeat('\\*', 10) . '…', $out);
        self::assertSame('abc', PlanText::inline('  abc  '));
        self::assertSame('', PlanText::inline(" \n\t "));
    }

    /** @return void */
    public function testInvalidUtf8DoesNotLoseTheText(): void {
        $out = PlanText::inline("a\xC3b");
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertStringStartsWith('a', $out);
        self::assertStringEndsWith('b', $out);
    }

    /** @return void */
    public function testStrongAndEmphasisWrapTheEscapedText(): void {
        self::assertSame('**\\*\\*x\\*\\***', PlanText::strong('**x**'));
        self::assertSame('*a\\_b*', PlanText::em('a_b'));
        self::assertSame('', PlanText::strong(" \n "));
        self::assertSame('', PlanText::em(''));
    }

    /** @return void */
    public function testQuoteKeepsEveryLineInsideTheBlock(): void {
        $out = PlanText::quote("hi [x](javascript:alert(1))\r# Heading\r\n**b**\n\nend");
        self::assertSame(
            "> hi \\[x\\]\\(javascript:alert(1))\n> \\# Heading\n> \\*\\*b\\*\\*\n>\n> end",
            $out
        );
        foreach (explode("\n", $out) as $line) {
            self::assertStringStartsWith('>', $line);
        }
    }

    /** @return void */
    public function testQuoteCutsLongTextAndIgnoresALoneCarriageReturnAsATrap(): void {
        $out = PlanText::quote(str_repeat('a', 300) . "\r# x", 50);
        self::assertSame('> ' . str_repeat('a', 50) . '…', $out);
        self::assertSame('', PlanText::quote(" \n "));
    }

    /** @return void */
    public function testFlatOnlyCollapsesAndCutsForDataThatIsEscapedLater(): void {
        self::assertSame("**x** [l] a b", PlanText::flat("**x** [l]\n\ta   b"));
        self::assertSame('abc…', PlanText::flat('abcdef', 3));
    }

    /** @return void */
    public function testCodeKeepsAValueExactlyAndCannotBeClosedFromInside(): void {
        self::assertSame('`/calendars/alice/team/`', PlanText::code('/calendars/alice/team/'));
        self::assertSame("`a'b *c* [d](e)`", PlanText::code("a`b\n*c* [d](e)"));
        self::assertSame('', PlanText::code(" \n "));
    }

    public function testQuotedNamesKeepSimpleTextReadableAndEscapeTheirDelimiters(): void {
        self::assertSame('"Work"', PlanText::quoted('Work'));
        self::assertSame('"Health, Fitness"', PlanText::quoted('Health, Fitness'));
        self::assertSame('"Saúde"', PlanText::quoted('Saúde'));
        self::assertSame('"A”, “B"', PlanText::quoted('A”, “B'));
        foreach (['A", "B', 'back\\slash', 'literal\\u0020', "a  b", "a\u{00a0}b", "a\u{200b}b"] as $name) {
            $json = str_replace('\\\\', '\\', PlanText::quoted($name));
            self::assertSame($name, json_decode($json, true, 512, JSON_THROW_ON_ERROR), 'the displayed JSON string retains the exact name');
        }
    }

    public function testQuotedWhitespaceAndFormatCharactersAreVisible(): void {
        self::assertSame('"a\\\\u0020\\\\u0020b"', PlanText::quoted('a  b'));
        self::assertSame('"a\\\\u00a0b"', PlanText::quoted("a\u{00a0}b"));
        self::assertSame('"a\\\\u2028b"', PlanText::quoted("a\u{2028}b"));
        self::assertSame('"a\\\\u200bb"', PlanText::quoted("a\u{200b}b"));
        self::assertSame('"a\\\\u202eb"', PlanText::quoted("a\u{202e}b"));
        self::assertSame('"a\\\\nb"', PlanText::quoted("a\nb"));
        self::assertNotSame(PlanText::quoted('a  b'), PlanText::quoted('a b'));
        self::assertNotSame(PlanText::quoted("a\u{00a0}b"), PlanText::quoted('a\\u00a0b'));
    }

    public function testQuotedNamesRemainInertMarkdownAndAreNeverCutAfterExpansion(): void {
        $out = PlanText::quoted('* _ ` [x](https://evil.example) <b> & www.evil.example');
        self::assertStringContainsString('\\* \\_ \\` \\[x\\]\\(', $out);
        self::assertStringNotContainsString('https://', $out);
        self::assertStringNotContainsString('www.', $out);
        self::assertStringNotContainsString('<b>', $out);
        $name = 'a' . str_repeat(' ', 253) . '"';
        $out = PlanText::quoted($name);
        self::assertGreaterThan(255, mb_strlen($out));
        self::assertStringNotContainsString('…', $out);
        self::assertSame($name, json_decode(str_replace('\\\\', '\\', $out), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testQuotedDelAndC1ControlsAreVisibleAndReversible(): void {
        foreach ([
            '007f' => "a\x7fb",
            '0085' => "a\u{85}b",
            '009b' => "a\u{9b}b",
        ] as $unit => $name) {
            $out = PlanText::quoted($name);
            self::assertSame('"a\\\\u' . $unit . 'b"', $out);
            self::assertSame(0, preg_match('/\p{Cc}/u', $out), 'no control character remains in the plan');
            self::assertSame($name, json_decode(str_replace('\\\\', '\\', $out), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testQuotedC0ControlsAreNotEscapedTwiceAndSupplementaryFormatCharactersStayReversible(): void {
        foreach (range(0, 31) as $control) {
            $name = 'a' . chr($control) . 'b';
            $json = str_replace('\\\\', '\\', PlanText::quoted($name));
            self::assertSame($name, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        }
        $name = "a\u{e0001}b";
        $out = PlanText::quoted($name);
        self::assertSame('"a\\\\udb40\\\\udc01b"', $out, 'a supplementary code point uses a JSON surrogate pair');
        self::assertSame($name, json_decode(str_replace('\\\\', '\\', $out), true, 512, JSON_THROW_ON_ERROR));
    }
}
