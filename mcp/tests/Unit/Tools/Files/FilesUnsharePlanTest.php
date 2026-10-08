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
use OCP\Share\IShare;

/** The text a person reads before confirming files_unshare, rendered from the plans the module really returns. */
final class FilesUnsharePlanTest extends FilesToolsTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->tree->addFolder('/alice/files/Projetos');
        $this->sharePolicy->setGrant('alice', 'files', 'share', true);
        $this->sharePolicy->setGrant('alice', 'files', 'link', true);
    }

    protected function tearDown(): void {
        Translator::reset();
        parent::tearDown();
    }

    /** @param array<string, mixed> $options share options; the node is /Documentos/ata.md unless it says otherwise */
    private function share(array $options): IShare {
        return $this->shares->add($options + ['node' => $this->tree->nodes['/alice/files/Documentos/ata.md']['id'],
            'nodeObject' => $this->tree->node('/alice/files/Documentos/ata.md')]);
    }

    private function body(array $arguments): string {
        $body = $this->module->renderPlan('files_unshare', $this->plan('files_unshare', $arguments));
        self::assertNotNull($body);
        return $body;
    }

    public function testAPerson(): void {
        $share = $this->share(['with' => 'bruno']);
        self::assertSame(implode("\n", [
            '**Bruno Lima** will no longer have access to **/Documentos/ata.md**.',
            '',
            '- Nothing is deleted: the file stays exactly as it is.',
        ]), $this->body(['shareId' => $share->getFullId()]));
    }

    public function testAGroupOnAFolder(): void {
        $this->shares->add(['node' => $this->tree->nodes['/alice/files/Projetos']['id'], 'nodeType' => 'folder', 'type' => IShare::TYPE_GROUP,
            'with' => 'finance', 'nodeObject' => $this->tree->node('/alice/files/Projetos')]);
        self::assertSame(implode("\n", [
            'Every member of the group **Financeiro** will lose access to **/Projetos**.',
            '',
            '- Nothing is deleted: the folder and everything in it stay exactly as they are.',
        ]), $this->body(['path' => '/Projetos', 'with' => 'group:finance']));
    }

    public function testALink(): void {
        $this->share(['type' => IShare::TYPE_LINK, 'with' => null, 'token' => 'TOK']);
        self::assertSame(implode("\n", [
            'The link to **/Documentos/ata.md** will stop working; anyone who has the link loses access.',
            '',
            '- Nothing is deleted: the file stays exactly as it is.',
        ]), $this->body(['path' => '/Documentos/ata.md', 'with' => 'link']));
    }

    /** The whole confirmation, envelope included, as the person reads it in Portuguese: names, never ids. */
    public function testTheWholePlanInPortuguese(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $this->share(['with' => 'bruno']);
        $this->share(['type' => IShare::TYPE_GROUP, 'with' => 'finance']);
        $this->share(['type' => IShare::TYPE_LINK, 'with' => null, 'token' => 'TOK']);
        $definition = array_column($this->module->definitions(), null, 'name')['files_unshare'];
        $render = fn (array $arguments): string => PlanRenderer::render($this->module, 'files_unshare',
            \OCA\Mcp\Tools\WriteGate::plan($this->module, $definition, $this->validated('files_unshare', $arguments), 'alice'));

        $person = $render(['path' => '/Documentos/ata.md', 'with' => 'user:bruno']);
        self::assertStringContainsString('**Bruno Lima** deixará de ter acesso a **/Documentos/ata.md**.', $person);
        self::assertStringContainsString('- Nada é apagado: o arquivo continua exatamente como está.', $person);
        self::assertStringContainsString('Nada foi alterado.', $person);
        self::assertStringNotContainsString('bruno', $person, 'a pessoa lê o nome, não o id');
        self::assertStringContainsString('Todos os membros do grupo **Financeiro** perderão o acesso a **/Documentos/ata.md**.',
            $render(['path' => '/Documentos/ata.md', 'with' => 'group:finance']));
        self::assertStringContainsString('O link de **/Documentos/ata.md** deixará de funcionar; quem tiver o link perde o acesso.',
            $render(['path' => '/Documentos/ata.md', 'with' => 'link']));
    }

    public function testAPlanWithoutAPathOrANameRendersNothing(): void {
        self::assertNull($this->module->renderPlan('files_unshare', []));
        self::assertNull($this->module->renderPlan('files_unshare', ['path' => '/a.md', 'with' => ['type' => 'user']]));
    }
}
