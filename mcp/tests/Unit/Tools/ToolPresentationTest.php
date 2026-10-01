<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\ToolPresentation;
use PHPUnit\Framework\TestCase;

final class ToolPresentationTest extends TestCase {
    /** English source title and the pt-BR title the app showed before the translation, by technical tool name. */
    private const TITLES = [
        'mcp_status' => ['Check MCP status', 'Verificar o status do MCP'],
        'mcp_guide' => ['Tool guide', 'Guia das ferramentas'],
        'files_list' => ['List files', 'Listar arquivos'],
        'files_search' => ['Search files', 'Buscar arquivos'],
        'files_read' => ['Read file', 'Ler arquivo'],
        'files_edit' => ['Edit file', 'Editar arquivo'],
        'files_tree' => ['View folder tree', 'Ver árvore de pastas'],
        'files_mkdir' => ['Create folder', 'Criar pasta'],
        'files_copy' => ['Copy file', 'Copiar arquivo'],
        'files_move' => ['Move file', 'Mover arquivo'],
        'files_move_batch' => ['Move files in bulk', 'Mover arquivos em lote'],
        'files_undo_batch' => ['Undo bulk operation', 'Desfazer operação em lote'],
        'files_replace' => ['Replace part of a file', 'Substituir trecho do arquivo'],
        'files_checkout' => ['Download file for local editing', 'Baixar arquivo para edição local'],
        'files_versions_list' => ['List file versions', 'Listar versões do arquivo'],
        'files_version_read' => ['Read file version', 'Ler versão do arquivo'],
        'files_version_restore' => ['Restore file version', 'Restaurar versão do arquivo'],
        'notes_list' => ['List notes', 'Listar notas'],
        'notes_read' => ['Read note', 'Ler nota'],
        'notes_create' => ['Create note', 'Criar nota'],
        'notes_edit' => ['Edit note', 'Editar nota'],
        'notes_move' => ['Move note', 'Mover nota'],
        'notes_delete' => ['Delete note', 'Excluir nota'],
        'calendar_list_calendars' => ['List calendars', 'Listar calendários'],
        'calendar_list_events' => ['List events', 'Listar eventos'],
        'calendar_create_event' => ['Create event', 'Criar evento'],
        'calendar_update_event' => ['Edit event', 'Editar evento'],
        'calendar_move_event' => ['Move event to another calendar', 'Mover evento para outro calendário'],
        'calendar_transfer_event' => ["Transfer event to another person's calendar", 'Transferir evento para calendário de outra pessoa'],
        'calendar_delete_event' => ['Delete event', 'Excluir evento'],
        'deck_list_boards' => ['List Deck boards', 'Listar quadros do Deck'],
        'deck_list_stacks' => ['List Deck lists', 'Listar listas do Deck'],
        'deck_list_cards' => ['List Deck cards', 'Listar cards do Deck'],
        'deck_read_card' => ['Read Deck card', 'Ler card do Deck'],
        'deck_create_card' => ['Create card in Deck', 'Criar card no Deck'],
        'deck_edit_card' => ['Edit Deck card', 'Editar card do Deck'],
        'deck_move_card' => ['Move card in Deck', 'Mover card no Deck'],
        'deck_delete_card' => ['Delete Deck card', 'Excluir card do Deck'],
        'deck_followup_cards' => ['Follow up on Deck tasks', 'Acompanhar tarefas do Deck'],
        'talk_list_conversations' => ['List Talk conversations', 'Listar conversas do Talk'],
        'talk_read_messages' => ['Read Talk messages', 'Ler mensagens do Talk'],
        'talk_reply' => ['Reply in Talk', 'Responder no Talk'],
        'talk_attach_file' => ['Attach file in Talk', 'Anexar arquivo no Talk'],
        'talk_quote_file' => ['Quote file in Talk', 'Citar arquivo no Talk'],
        'talk_message_user' => ['Direct message in Talk', 'Mensagem direta no Talk'],
        'talk_send_batch' => ['Send batch in Talk', 'Enviar lote no Talk'],
        'talk_create_group' => ['Create group in Talk', 'Criar grupo no Talk'],
    ];

    protected function tearDown(): void {
        Translator::reset();
    }

    public function testWithoutTranslatorEveryTitleIsTheEnglishSource(): void {
        foreach (self::TITLES as $tool => [$english]) {
            $this->assertSame($english, ToolPresentation::title($tool), $tool);
        }
    }

    public function testPortugueseUserKeepsTheTitlesOfBefore(): void {
        Translator::use(new JsonL10n('pt_BR'));
        foreach (self::TITLES as $tool => [, $portuguese]) {
            $this->assertSame($portuguese, ToolPresentation::title($tool), $tool);
        }
    }

    public function testSpanishUserGetsSpanishTitles(): void {
        Translator::use(new JsonL10n('es'));
        foreach (self::TITLES as $tool => [$english]) {
            $this->assertNotSame('', ToolPresentation::title($tool));
            $this->assertNotSame($english, ToolPresentation::title($tool), $tool . ' has no Spanish title');
        }
        $this->assertSame('Listar archivos', ToolPresentation::title('files_list'));
    }

    public function testAnnotationsCarryTheTranslatedTitle(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $this->assertSame('Listar arquivos', ToolPresentation::annotations('files_list', 'read')['title']);
    }

    public function testUnknownToolFallsBackToTheHumanizedName(): void {
        $this->assertSame('Frobnicate', ToolPresentation::title('x_frobnicate'));
    }

    public function testInstructionsAreEnglishAndAskForTheUserLanguageAndTheTitles(): void {
        $this->assertStringContainsString("user's language", ToolPresentation::INSTRUCTIONS);
        $this->assertStringContainsString('title', ToolPresentation::INSTRUCTIONS);
        $this->assertDoesNotMatchRegularExpression('/[À-ÿ]/u', ToolPresentation::INSTRUCTIONS);
    }
}
