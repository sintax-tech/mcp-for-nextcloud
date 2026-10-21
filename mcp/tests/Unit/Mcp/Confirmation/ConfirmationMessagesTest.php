<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Confirmation;
use OCA\Mcp\Mcp\Confirmation\ConfirmationMessages;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\TestCase;
final class ConfirmationMessagesTest extends TestCase {
    protected function tearDown(): void { Translator::reset(); }
    public function testExactInstructions(): void {
        foreach (['en', 'pt_BR', 'es'] as $locale) {
            Translator::use(new JsonL10n($locale));
            $published = WriteGate::publish(['name' => 'files_edit', 'module' => 'files', 'operation' => 'edit',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]]);
            self::assertSame(ConfirmationMessages::confirmParameter(), $published['inputSchema']['properties']['confirm']['description']);
            self::assertStringContainsString('confirm: true', ConfirmationMessages::planStateParameter());
            self::assertNotSame('', ConfirmationMessages::confirmAdviceUpload());
        }
    }
}
