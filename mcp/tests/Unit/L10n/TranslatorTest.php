<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\L10n;

use OCA\Mcp\L10n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase {
    protected function tearDown(): void {
        Translator::reset();
    }

    public function testWithoutUseTheSourceTextComesOutInEnglish(): void {
        $this->assertSame('Disconnect', Translator::t('Disconnect'));
        $this->assertSame('Endpoint: /mcp', Translator::t('Endpoint: %s', ['/mcp']));
    }

    public function testUseTranslatesThroughTheGivenL10n(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $this->assertSame('Desconectar', Translator::t('Disconnect'));
        $this->assertSame('Endpoint: /mcp', Translator::t('Endpoint: %s', ['/mcp']));
    }

    public function testResetGoesBackToEnglishSoNothingLeaksBetweenRequests(): void {
        Translator::use(new JsonL10n('pt_BR'));
        Translator::reset();
        $this->assertSame('Disconnect', Translator::t('Disconnect'));
    }

    public function testPluralWithoutUseUsesTheEnglishForms(): void {
        $this->assertSame('1 note', Translator::n('%n note', '%n notes', 1));
        $this->assertSame('3 notes', Translator::n('%n note', '%n notes', 3));
    }

    public function testPluralThroughTheL10n(): void {
        Translator::use(new JsonL10n('en'));
        $this->assertSame('2 notes', Translator::n('%n note', '%n notes', 2));
    }

    public function testALanguageWithoutFileFallsBackToEnglishWithoutError(): void {
        Translator::use(new JsonL10n('de'));
        $this->assertSame('Disconnect', Translator::t('Disconnect'));
    }

    public function testLocaleDefaultsToEnglish(): void {
        $this->assertSame('en', Translator::locale());
    }

    public function testLocaleReflectsActiveL10n(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $this->assertSame('pt_BR', Translator::locale());
        Translator::use(new JsonL10n('es'));
        $this->assertSame('es', Translator::locale());
        Translator::reset();
        $this->assertSame('en', Translator::locale());
    }
}
