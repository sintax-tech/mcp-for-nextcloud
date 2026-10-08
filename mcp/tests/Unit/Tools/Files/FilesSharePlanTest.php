<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\PlanRenderer;

/** The text a person reads before confirming files_share, rendered from the plans the module really returns. */
final class FilesSharePlanTest extends FilesToolsTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFolder('/alice/files/Projetos');
        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
    }

    protected function tearDown(): void {
        Translator::reset();
        parent::tearDown();
    }

    private function body(array $arguments): string {
        $body = $this->module->renderPlan('files_share', $this->plan('files_share', $arguments));
        self::assertNotNull($body);
        return $body;
    }

    public function testANewShareWithAPerson(): void {
        self::assertSame(implode("\n", [
            'Share **/Documentos/ata.md** with **Bruno Lima**.',
            '',
            '- Access: can view and download',
            '- Valid until: 12/31/2026.',
            '- Note for the recipient: «Revise até sexta»',
            '- Nextcloud notifies **Bruno Lima**.',
        ]), $this->body(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-12-31', 'note' => 'Revise até sexta']));
    }

    public function testANewShareOfAFolderWithAGroupAndTheAdministratorsDefault(): void {
        $this->shares->settings['shareApiInternalDefaultExpireDate'] = true;
        $this->shares->settings['shareApiInternalDefaultExpireDays'] = 10;
        self::assertSame(implode("\n", [
            'Share **/Projetos** with the group **Financeiro**.',
            '',
            '- Access: can view, add, edit and delete inside it',
            '- Valid until: 10/01/2026 (the administrator\'s default).',
            '- Nextcloud notifies the members of **Financeiro**.',
        ]), $this->body(['path' => '/Projetos', 'with' => 'group:finance', 'permission' => 'edit']));
    }

    /** A share made on the web carries the re-share right; the update takes it away and the plan says so plainly. */
    public function testAnUpdateThatTakesTheReshareRightAway(): void {
        $this->shares->add(['node' => $this->tree->nodes['/alice/files/Documentos/ata.md']['id'], 'with' => 'bruno', 'permissions' => 19,
            'note' => 'oi', 'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);
        self::assertSame(implode("\n", [
            'Change how **/Documentos/ata.md** is shared with **Bruno Lima**.',
            '',
            '- Access: can view and edit → can view and download',
            '- Passing it on: **Bruno Lima** can share it with other people today and will no longer be able to.',
            '- Valid until: no end date → 10/15/2026.',
            '- Note for the recipient: «oi» → «Só leitura agora»',
        ]), $this->body(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'expires' => '2026-10-15', 'note' => 'Só leitura agora']));
    }

    public function testNothingToChange(): void {
        $this->shares->add(['node' => $this->tree->nodes['/alice/files/Documentos/ata.md']['id'], 'with' => 'bruno', 'permissions' => 3,
            'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);
        self::assertSame(implode("\n", [
            '**/Documentos/ata.md** is already shared with **Bruno Lima** exactly like this: nothing to change.',
            '',
            '- Access: can view and edit',
            '- Valid until: no end date.',
        ]), $this->body(['path' => '/Documentos/ata.md', 'with' => 'user:bruno', 'permission' => 'edit']));
    }

    /** The whole confirmation, envelope included, as the person reads it in Portuguese. */
    public function testTheWholePlanInPortuguese(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $this->shares->add(['node' => $this->tree->nodes['/alice/files/Documentos/ata.md']['id'], 'with' => 'bruno', 'permissions' => 19,
            'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);
        $plan = \OCA\Mcp\Tools\WriteGate::plan($this->module, array_column($this->module->definitions(), null, 'name')['files_share'],
            $this->validated('files_share', ['path' => '/Documentos/ata.md', 'with' => 'user:bruno']), 'alice');
        $text = PlanRenderer::render($this->module, 'files_share', $plan);

        self::assertStringContainsString('Alterar como **/Documentos/ata.md** está compartilhado com **Bruno Lima**.', $text);
        self::assertStringContainsString('- Acesso: pode ver e editar → pode ver e baixar', $text);
        self::assertStringContainsString('**Bruno Lima** pode compartilhar com outras pessoas hoje e não poderá mais.', $text);
        self::assertStringContainsString('Nada foi alterado.', $text);
        self::assertStringNotContainsString('bruno', $text, 'a pessoa lê o nome, não o id');
    }
}
