<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Files_Versions\Versions\FakeVersion;
use OCA\Files_Versions\Versions\FakeVersionManager;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\FilesPlanRenderer;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Files\VersionTools;
use OCA\Mcp\Tools\RendersPlans;
use PHPUnit\Framework\Attributes\DataProvider;

/** The Markdown a person reads for every Files write plan, built from the plans the module really returns. */
final class FilesPlanRendererTest extends FilesToolsTestCase {
    use \OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;

    protected function setUp(): void {
        parent::setUp();
        require_once __DIR__ . '/Stubs/versions_api.php';
        $this->tree->addFile('/alice/files/Documentos/plano.md', "um\ndois\n", 'text/markdown');
        $this->tree->addFolder('/alice/files/Arquivo');
        $backup = $this->createMock(FileBackup::class);
        $manager = FakeVersionManager::with([new FakeVersion(1759100000, 1759100000, 4, 'text/markdown', 'ata.md')]);
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $id === 'OCA\Files_Versions\Versions\IVersionManager' ? $manager : null);
        $access = new NodeAccessInfo(FakeUsers::manager($this, FakeUsers::DEFAULTS), $this->tree->shareManager());
        $versions = new VersionTools($this->apps, $this->users, new TextExtractor($this->temp), $backup, $access, $container, new \OCA\Mcp\Tools\Files\OcrSupport($this->apps));
        $args = [];
        foreach ((new \ReflectionClass(FilesModule::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $args[] = $name === 'versions' ? $versions : (new \ReflectionProperty(FilesModule::class, $name))->getValue($this->module);
        }
        $this->module = new FilesModule(...$args);
    }

    protected function tearDown(): void {
        Translator::reset();
        parent::tearDown();
    }

    /** @return string the body of the tool's plan, in the language of the current translator */
    private function body(string $tool, array $arguments): string {
        $body = $this->module->renderPlan($tool, $this->plan($tool, $arguments));
        $this->assertNotNull($body, $tool);
        $this->assertStringNotContainsString('{', $body, 'no JSON in a human text');
        return $body;
    }

    /** @return array<string, array{string, array<string, mixed>}> every write tool with arguments that plan cleanly */
    public static function toolsProvider(): array {
        return [
            'edit' => ['files_edit', ['path' => '/Documentos/plano.md', 'content' => "um\ntrês\n"]],
            'replace' => ['files_replace', ['path' => '/Documentos/plano.md', 'old' => 'dois', 'new' => 'quatro']],
            'checkout' => ['files_checkout', ['path' => '/Documentos/ata.md']],
            'copy' => ['files_copy', ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']],
            'mkdir' => ['files_mkdir', ['path' => '/Arquivo/2026/Q3']],
            'move' => ['files_move', ['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']],
            'batch' => ['files_move_batch', ['moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']], 'mkdirs' => ['/Novo']]],
            'restore' => ['files_version_restore', ['path' => '/Documentos/ata.md', 'version' => '1759100000']],
        ];
    }

    #[DataProvider('toolsProvider')]
    public function testEveryToolRendersInEnglishAndPortuguese(string $tool, array $arguments): void {
        $english = $this->body($tool, $arguments);
        Translator::use(new JsonL10n('pt_BR'));
        $portuguese = $this->body($tool, $arguments);

        $this->assertNotSame($english, $portuguese);
        $this->assertStringContainsString('/', $english);
    }

    public function testModuleIsAPlanRenderer(): void {
        $this->assertInstanceOf(RendersPlans::class, $this->module);
    }

    public function testEditShowsBeforeAfterSizeAndBackup(): void {
        $body = $this->body('files_edit', ['path' => '/Documentos/plano.md', 'content' => "um\ntrês\n"]);

        $this->assertStringContainsString('**/Documentos/plano.md**', $body);
        $this->assertStringContainsString('«dois»', $body);
        $this->assertStringContainsString('«três»', $body);
        $this->assertStringContainsString('8 B → 9 B', $body);
        $this->assertStringContainsString('MCP backups', $body);
    }

    public function testEditExcerptIsCutAt200Characters(): void {
        $this->tree->addFile('/alice/files/Documentos/longo.md', "x\n", 'text/markdown');
        $body = $this->body('files_edit', ['path' => '/Documentos/longo.md', 'content' => str_repeat('a', 500) . "\n"]);

        $this->assertStringContainsString(str_repeat('a', 200) . '…', $body);
        $this->assertStringNotContainsString(str_repeat('a', 201), $body);
    }

    public function testEditOfASharedFileSaysWhoSharedItAndAsksForTheExtraConfirmation(): void {
        $this->tree->addFile('/alice/files/Compartilhado/plano.md', "a\n", 'text/markdown', ['scope' => 'shared']);
        Translator::use(new JsonL10n('pt_BR'));
        $body = $this->body('files_edit', ['path' => '/Compartilhado/plano.md', 'content' => "b\n"]);

        $this->assertStringContainsString('Pedro Almeida', $body);
        $this->assertStringContainsString('confirmação', $body);
    }

    public function testMoveBatchInPortugueseListsFromToCreatedFoldersAndUndo(): void {
        $this->tree->addFile('/alice/files/Documentos/ata.md', 'ata', 'text/markdown');
        Translator::use(new JsonL10n('pt_BR'));
        $body = $this->body('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md'], ['from' => '/Documentos/nada.md', 'to' => '/Arquivo/nada.md']],
            'mkdirs' => ['/Novo'],
        ]);

        $this->assertStringContainsString('Mover 1 item:', $body);
        $this->assertStringContainsString('**/Documentos/ata.md** → **/Arquivo/ata.md**', $body);
        $this->assertSame(1, substr_count($body, '**/Documentos/nada.md**'), 'the denied item shows once in its heading, not in the move list');
        $this->assertStringNotContainsString("- **/Documentos/nada.md** → **/Arquivo/nada.md**\n\nPastas", $body);
        $this->assertStringContainsString('**/Novo**', $body);
        $this->assertStringContainsString('desfazer', $body);
    }

    public function testEditInPortugueseText(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $body = $this->body('files_edit', ['path' => '/Documentos/plano.md', 'content' => "um\ntrês\n"]);

        $this->assertStringContainsString('Sobrescrever o conteúdo de', $body);
        $this->assertStringContainsString('Antes:', $body);
    }

    public function testUndoBatchListsWhatGoesBack(): void {
        $id = $this->json('files_move_batch', [
            'moves' => [['from' => '/Documentos/ata.md', 'to' => '/Arquivo/ata.md']],
            'confirm' => true,
        ])['batch_id'];
        $body = $this->body('files_undo_batch', ['batch_id' => $id]);

        $this->assertStringContainsString('**/Arquivo/ata.md** → **/Documentos/ata.md**', $body);
        Translator::use(new JsonL10n('pt_BR'));
        $this->assertStringContainsString('Desfazer', $this->body('files_undo_batch', ['batch_id' => $id]));
    }

    public function testRestoreShowsTheVersionDateInTheUserTimezone(): void {
        $plan = $this->plan('files_version_restore', ['path' => '/Documentos/ata.md', 'version' => '1759100000']);
        $plan['timezone'] = 'America/Sao_Paulo';

        $body = (string)$this->module->renderPlan('files_version_restore', $plan);

        // 1759100000 = 2025-09-28 22:53:20 UTC = 19:53 in São Paulo.
        $this->assertStringContainsString('09/28/2025 19:53', $body);
    }

    public function testRestoreDateIsInTheLanguageFormatOfTheUser(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $plan = $this->plan('files_version_restore', ['path' => '/Documentos/ata.md', 'version' => '1759100000']);
        $plan['timezone'] = 'America/Sao_Paulo';

        $this->assertStringContainsString('Voltar **/Documentos/ata.md** para a versão de 28/09/2025 19:53.', (string)$this->module->renderPlan('files_version_restore', $plan));
    }

    public function testMissingKeysGiveNull(): void {
        foreach (['files_edit', 'files_replace', 'files_checkout', 'files_copy', 'files_mkdir', 'files_move', 'files_move_batch', 'files_undo_batch', 'files_version_restore', 'files_list'] as $tool) {
            $this->assertNull(FilesPlanRenderer::render($tool, []), $tool);
            $this->assertNull($this->module->renderPlan($tool, ['unknown' => 1]), $tool);
        }
    }

    public function testGarbageInThePlanNeverThrows(): void {
        $this->assertNull(FilesPlanRenderer::render('files_move_batch', ['order' => 'x']));
        $this->assertNull(FilesPlanRenderer::render('files_edit', ['path' => ['a'], 'diff' => 5]));
    }

    /** Every write tool of the module renders a plan of its own: none falls back to the generic field list. */
    public function testEveryWriteToolHasAReadablePlan(): void {
        $plans = [];
        foreach (self::toolsProvider() as [$tool, $arguments]) {
            $plans[$tool] = $this->plan($tool, $arguments);
        }
        // An undo needs a batch that really ran, so one move is confirmed first and its id is undone.
        $this->tree->addFile('/alice/files/Documentos/lote.md', 'lote', 'text/markdown');
        $ran = $this->module->call('files_move_batch', ['moves' => [['from' => '/Documentos/lote.md', 'to' => '/Arquivo/lote.md']], 'confirm' => true], 'alice');
        $batchId = json_decode($ran['content'][0]['text'], true)['batch_id'];
        $plans['files_undo_batch'] = $this->plan('files_undo_batch', ['batch_id' => $batchId]);
        $this->assertEveryWriteToolHasAReadablePlan($this->module, $plans);
    }

    public function testNamesWithMarkdownStayLiteralInsteadOfBreakingTheBold(): void {
        $body = (string)FilesPlanRenderer::render('files_move', ['from' => 'a_b*c.md', 'to' => 'x/[clique](javascript:alert(1)).md']);

        $this->assertSame('Move **a\\_b\\*c.md** to **x/\\[clique\\]\\(javascript:alert(1)).md**.', trim($body));
    }

    public function testADiffCannotOpenAnotherLineOrLinkOfThePlan(): void {
        $body = (string)FilesPlanRenderer::render('files_edit', ['path' => 'a.md', 'diff' => "-old\n+**new**\n+[c](javascript:1)\n+\n+### Warnings"]);

        $this->assertStringContainsString('After: «\\*\\*new\\*\\* \\[c\\]\\(javascript:1) \\#\\#\\# Warnings»', $body);
        $this->assertStringNotContainsString("\n### ", $body);
    }
}
