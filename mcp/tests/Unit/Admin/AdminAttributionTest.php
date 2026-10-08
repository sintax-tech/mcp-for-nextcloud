<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\TestCase;

final class AdminAttributionTest extends TestCase {
    /** The final footer stays visible and provides a text link to Sintax in each shipped language. */
    public function testAdminFooterHasVisibleTranslatedAttributionAndAccessibleLink(): void {
        $template = (string)file_get_contents(__DIR__ . '/../../../templates/admin.php');
        $this->assertMatchesRegularExpression('~<footer class="mcp-attribution">(.*?)</footer>\s*</div>\s*$~s', $template);
        preg_match('~<footer class="mcp-attribution">(.*?)</footer>~s', $template, $match);
        $footer = $match[1];
        $this->assertStringContainsString("p(\$l->t('Developed by Sintax'))", $footer);
        $this->assertStringContainsString('<a href="https://sintax.tech" target="_blank" rel="noopener noreferrer">sintax.tech</a>', $footer);
        foreach (['en' => 'Developed by Sintax', 'pt_BR' => 'Desenvolvido pela Sintax', 'es' => 'Desarrollado por Sintax'] as $language => $expected) {
            $l = new JsonL10n($language);
            $this->assertSame($expected, $l->t('Developed by Sintax'));
        }
    }
}
