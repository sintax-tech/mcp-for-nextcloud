<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\PlanRenderer;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolModule;
use PHPUnit\Framework\TestCase;

/** Readable plans preserve the structured payload while bounding generic text. */
final class PlanRendererTest extends TestCase {
    protected function tearDown(): void { Translator::reset(); }

    /** @return ToolModule a renderer whose null answer requests the generic body */
    private function module(?string $body = null, bool $throws = false): ToolModule {
        return new class($body, $throws) implements ToolModule, RendersPlans {
            public function __construct(private ?string $body, private bool $throws) {}
            public function definitions(): array { return []; }
            public function call(string $name, array $arguments, string $userId): array { return []; }
            public function renderPlan(string $tool, array $plan): ?string {
                if ($this->throws) { throw new \Error('broken renderer'); }
                return $this->body;
            }
        };
    }

    public function testGenericTypesAndExcludedFields(): void {
        $text = PlanRenderer::render($this->module(), 'fake_write', [
            'title' => 'My plan', 'tool' => 'fake_write', 'requiresConfirmation' => true,
            'message' => 'Original instruction.', 'action' => 'secret-action', 'etag' => 'secret-etag',
            'destination' => '/Archive', 'recoverable' => true, 'enabled' => false,
            'tags' => ['a', 'b', 3, false], 'count' => 0, 'empty' => [], 'nothing' => null,
            'customField' => ['child' => ['leaf' => ['hidden' => 'deep-secret']]],
            'description' => str_repeat('x', 301),
        ]);
        self::assertStringStartsWith('**My plan**', $text);
        foreach (['Destination: /Archive', 'Recoverable: yes', 'Enabled: no', 'Tags: a, b, 3, no', 'Count: 0', 'Custom field:', '  - Child:', '    - Leaf:', str_repeat('x', 300) . '...', '*Original instruction.*'] as $part) {
            self::assertStringContainsString($part, $text);
        }
        foreach (['secret-action', 'secret-etag', 'fake_write', 'Empty:', 'Nothing:', 'deep-secret', str_repeat('x', 301)] as $part) {
            self::assertStringNotContainsString($part, $text);
        }
    }

    public function testWarningsSharedCalendarsAndSuggestionInPortuguese(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $text = PlanRenderer::render($this->module('Corpo específico.'), 'calendar_create_event', [
            'title' => 'Criar evento', 'warnings' => [['type' => 'overlap', 'message' => 'Há conflito.']],
            'sharedCalendars' => [['path' => '/cal/team', 'name' => 'Equipe']],
            'suggestedCalendar' => ['path' => '/cal/team', 'name' => 'Equipe'],
        ]);
        foreach (['Corpo específico.', '### Avisos', '- Há conflito.', '### Calendários compartilhados com os participantes', '- Equipe (/cal/team)', 'Sugestão: usar *Equipe*', 'Nada foi alterado. Confirme para executar.'] as $part) {
            self::assertStringContainsString($part, $text);
        }
    }

    public function testModuleBodyAndExceptionFallback(): void {
        self::assertStringContainsString('Custom body', PlanRenderer::render($this->module('Custom body'), 'fake', ['destination' => '/target']));
        self::assertStringNotContainsString('Destination:', PlanRenderer::render($this->module(''), 'fake', ['destination' => '/target']));
        self::assertStringContainsString('Destination: /target', PlanRenderer::render($this->module(null, true), 'fake', ['destination' => '/target']));
    }

    public function testEmptyPlan(): void {
        self::assertSame('Nothing was changed. Confirm to execute.', PlanRenderer::render($this->module(), 'fake', []));
    }

    public function testMovesAreReadableInPortuguese(): void {
        Translator::use(new JsonL10n('pt_BR'));
        self::assertStringContainsString('- de /Docs/a.md → para /Archive/a.md', PlanRenderer::render($this->module(), 'files_move_batch', [
            'moves' => [['from' => '/Docs/a.md', 'to' => '/Archive/a.md', 'ok' => true]],
        ]));
    }

    public function testContentWritesShowPathAndSizeWithoutContentOrDiff(): void {
        foreach (['files_edit', 'files_replace'] as $tool) {
            $text = PlanRenderer::render($this->module(), $tool, [
                'path' => '/file.md', 'size' => ['before' => 10, 'after' => 20],
                'content' => 'private body', 'old' => 'old body', 'new' => 'new body', 'diff' => 'private diff',
            ]);
            self::assertStringContainsString('Path: /file.md', $text);
            self::assertStringContainsString('Before: 10', $text);
            self::assertStringContainsString('After: 20', $text);
            foreach (['private body', 'old body', 'new body', 'private diff'] as $secret) { self::assertStringNotContainsString($secret, $text); }
        }
    }

    public function testPlainModuleUsesGenericBodyAndUnicodeIsCutAtCharacters(): void {
        $module = $this->createMock(ToolModule::class);
        $text = PlanRenderer::render($module, 'fake', ['description' => str_repeat('á', 301), 'arguments' => ['path' => '/file.md']]);
        self::assertStringContainsString(str_repeat('á', 300) . '...', $text);
        self::assertStringNotContainsString(str_repeat('á', 301), $text);
        self::assertStringContainsString('  - Path: /file.md', $text);
        self::assertStringNotContainsString('###', $text);
    }

    public function testSpanishGenericLabelsAndSuggestionWithoutSharedCalendars(): void {
        Translator::use(new JsonL10n('es'));
        $text = PlanRenderer::render($this->module(), 'fake', [
            'path' => '/file.md', 'size' => ['before' => 0, 'after' => 30], 'recoverable' => true,
            'suggestedCalendar' => ['path' => '/cal/team', 'name' => 'Equipo'],
        ]);
        foreach (['Ruta: /file.md', 'Tamaño (bytes):', 'Antes: 0', 'Después: 30', 'Recuperable: sí', 'Sugerencia: usar *Equipo*', 'No se ha cambiado nada. Confirme para ejecutar.'] as $part) {
            self::assertStringContainsString($part, $text);
        }
        self::assertStringNotContainsString('###', $text);
    }

    public function testUserTextCannotIntroduceMarkdownStructure(): void {
        $text = PlanRenderer::render($this->module(), 'fake', ['title' => '*title*', 'path' => "file\n### forged", 'message' => '*instruction*']);
        self::assertStringContainsString('**\\*title\\***', $text);
        self::assertStringNotContainsString("\n### forged", $text);
        self::assertStringContainsString('*\\*instruction\\**', $text);
    }
}
