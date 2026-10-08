<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares;
use OCA\Mcp\Tools\PlanRenderer;
use OCA\Mcp\Tools\WriteGate;
use OCP\Share\IShare;

/** The text a person reads before confirming a public link: who can open it, until when, and what about the password. */
final class FilesShareLinkPlanTest extends FilesToolsTestCase {
    private const FILE = '/Documentos/ata.md';

    protected function setUp(): void {
        parent::setUp();
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
    }

    protected function tearDown(): void {
        Translator::reset();
        parent::tearDown();
    }

    private function body(array $arguments): string {
        $body = $this->module->renderPlan('files_share', $this->plan('files_share', $arguments + ['with' => 'link']));
        self::assertNotNull($body);
        return $body;
    }

    private function whole(array $arguments): string {
        $plan = WriteGate::plan($this->module, array_column($this->module->definitions(), null, 'name')['files_share'],
            $this->validated('files_share', $arguments + ['with' => 'link']), 'alice');
        return PlanRenderer::render($this->module, 'files_share', $plan);
    }

    private function existingLink(array $o = []): IShare {
        return $this->shares->add($o + ['type' => IShare::TYPE_LINK, 'with' => null, 'token' => 't',
            'node' => $this->tree->nodes['/alice/files' . self::FILE]['id'], 'nodeObject' => $this->tree->node('/alice/files' . self::FILE)]);
    }

    public function testANewLinkWithoutPassword(): void {
        self::assertSame(implode("\n", [
            'Create a public link to **/Documentos/ata.md**.',
            '',
            '- Access: can view and download',
            '- Valid until: 12/31/2026.',
            '- Password: none.',
        ]), $this->body(['path' => self::FILE, 'expires' => '2026-12-31']));
    }

    /** The warning stands in the envelope, where every plan puts what the person must weigh before saying yes. */
    public function testTheWholePlanWarnsThatAnyoneWithTheLinkCanOpenIt(): void {
        self::assertStringContainsString("### Warnings\n\n- Anyone who has the link can open this, without signing in.", $this->whole(['path' => self::FILE]));
    }

    public function testARequiredPasswordAndTheAdministratorsDefaultValidity(): void {
        $this->shares->settings['shareApiLinkEnforcePassword'] = true;
        $this->shares->settings['shareApiLinkDefaultExpireDate'] = true;
        $this->shares->settings['shareApiLinkDefaultExpireDays'] = 14;
        self::assertSame(implode("\n", [
            'Create a public link to **/Documentos/ata.md**.',
            '',
            '- Access: can view and download',
            '- Valid until: 10/05/2026 (the administrator\'s default).',
            '- Password: required by the administrator; a new one will be generated and shown only once, in the result.',
        ]), $this->body(['path' => self::FILE]));
    }

    public function testAskingForANewPasswordOnAnExistingLink(): void {
        $this->existingLink(['password' => FakeShares::hashOf('old'), 'note' => 'para o cliente']);
        self::assertSame(implode("\n", [
            'Change the public link of **/Documentos/ata.md**.',
            '',
            '- Access: can view and download',
            '- Valid until: no end date.',
            '- Password: the current one will be replaced by a new one, shown only once, in the result.',
        ]), $this->body(['path' => self::FILE, 'password' => true]));
    }

    public function testAnUpdateThatKeepsThePassword(): void {
        $this->existingLink(['password' => FakeShares::hashOf('old'), 'expires' => new \DateTime('2026-10-01 12:00')]);
        self::assertSame(implode("\n", [
            'Change the public link of **/Documentos/ata.md**.',
            '',
            '- Access: can view and download',
            '- Valid until: 10/01/2026 → 10/20/2026.',
            '- Password: unchanged; it is never shown.',
            '- Note for the recipient: none → «Válido até o fim do mês»',
        ]), $this->body(['path' => self::FILE, 'expires' => '2026-10-20', 'note' => 'Válido até o fim do mês']));
    }

    public function testNothingToChange(): void {
        $this->existingLink();
        self::assertSame(implode("\n", [
            '**/Documentos/ata.md** already has a public link exactly like this: nothing to change.',
            '',
            '- Access: can view and download',
            '- Valid until: no end date.',
            '- Password: none.',
        ]), $this->body(['path' => self::FILE]));
    }

    public function testTheWholePlanInPortuguese(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $this->shares->settings['shareApiLinkEnforcePassword'] = true;
        $text = $this->whole(['path' => self::FILE]);
        self::assertStringContainsString('Criar um link público para **/Documentos/ata.md**.', $text);
        self::assertStringContainsString('Qualquer pessoa que tiver o link poderá abrir isto, sem entrar no Nextcloud.', $text);
        self::assertStringContainsString('- Senha: exigida pelo administrador; uma nova será gerada e mostrada uma única vez, no resultado.', $text);
        self::assertStringNotContainsString('notifica', $text, 'link não tem destinatário para notificar');
    }
}
