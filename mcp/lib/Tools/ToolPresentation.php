<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools;

/**
 * How tools are shown to the model and to the person reading the conversation.
 *
 * Clients such as claude.ai display the Tool `title` (and the `annotations.title`) instead of the
 * technical name, both in the live tool-use card and in the answer text; keeping the technical name
 * out of the visible layer is what makes the transcript readable. The map is central so a module can
 * add its tools without touching this class's callers.
 */
final class ToolPresentation {
    /** Guidance sent in `initialize` and in `server/discover`, so the model speaks the titles too. */
    public const INSTRUCTIONS = "Ao falar com o usuário, refira-se às ferramentas pelo título (ex.: \"Buscar arquivos\"), "
        . "nunca pelo nome técnico. Mostre caminhos e nomes de arquivos de forma legível.";

    /** Operations that only read; every other operation may change data. */
    private const READ_OPERATION = 'read';
    /** Operations whose whole point is to destroy, overwrite or relocate data. */
    private const DESTRUCTIVE_OPERATIONS = ['delete', 'restore', 'transfer', 'move'];

    /** Technical tool name to human-readable pt-BR title. A tool missing here falls back to a humanized name. */
    private const TITLES = [
        // Diagnóstico
        'mcp_status' => 'Verificar o status do MCP',
        // Files
        'files_list' => 'Listar arquivos',
        'files_search' => 'Buscar arquivos',
        'files_read' => 'Ler arquivo',
        'files_edit' => 'Editar arquivo',
        'files_tree' => 'Ver árvore de pastas',
        'files_mkdir' => 'Criar pasta',
        'files_copy' => 'Copiar arquivo',
        'files_move' => 'Mover arquivo',
        'files_move_batch' => 'Mover arquivos em lote',
        'files_undo_batch' => 'Desfazer operação em lote',
        'files_replace' => 'Substituir trecho do arquivo',
        'files_checkout' => 'Baixar arquivo para edição local',
        'files_versions_list' => 'Listar versões do arquivo',
        'files_version_read' => 'Ler versão do arquivo',
        'files_version_restore' => 'Restaurar versão do arquivo',
        // Notes
        'notes_list' => 'Listar notas',
        'notes_read' => 'Ler nota',
        'notes_create' => 'Criar nota',
        'notes_edit' => 'Editar nota',
        'notes_move' => 'Mover nota',
        'notes_delete' => 'Excluir nota',
        // Calendar
        'calendar_list_calendars' => 'Listar calendários',
        'calendar_list_events' => 'Listar eventos',
        'calendar_create_event' => 'Criar evento',
        'calendar_update_event' => 'Editar evento',
        'calendar_move_event' => 'Mover evento para outro calendário',
        'calendar_transfer_event' => 'Transferir evento para calendário de outra pessoa',
        'calendar_delete_event' => 'Excluir evento',
        // Deck
        'deck_list_boards' => 'Listar quadros do Deck',
        'deck_list_stacks' => 'Listar listas do Deck',
        'deck_list_cards' => 'Listar cards do Deck',
        'deck_read_card' => 'Ler card do Deck',
        'deck_create_card' => 'Criar card no Deck',
        'deck_edit_card' => 'Editar card do Deck',
        'deck_move_card' => 'Mover card no Deck',
        'deck_delete_card' => 'Excluir card do Deck',
        'deck_followup_cards' => 'Acompanhar tarefas do Deck',
        // Talk
        'talk_list_conversations' => 'Listar conversas do Talk',
        'talk_read_messages' => 'Ler mensagens do Talk',
        'talk_reply' => 'Responder no Talk',
        'talk_attach_file' => 'Anexar arquivo no Talk',
        'talk_quote_file' => 'Citar arquivo no Talk',
    ];

    private function __construct() {
    }

    /**
     * Human-readable title for a tool, falling back to the humanized technical name.
     *
     * @param string $name technical tool name
     */
    public static function title(string $name): string {
        return self::TITLES[$name] ?? self::humanized($name);
    }

    /**
     * MCP tool annotations derived from the grant operation the module declares.
     *
     * `openWorldHint` is always false: every tool works inside the caller's own Nextcloud.
     *
     * @param string $name technical tool name
     * @param string $operation grant operation of the tool definition (read, create, edit, delete, move, transfer, restore)
     * @return array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    public static function annotations(string $name, string $operation): array {
        $readOnly = $operation === self::READ_OPERATION;
        return [
            'title' => self::title($name),
            'readOnlyHint' => $readOnly,
            'destructiveHint' => in_array($operation, self::DESTRUCTIVE_OPERATIONS, true),
            'idempotentHint' => $readOnly,
            'openWorldHint' => false,
        ];
    }

    /**
     * Best-effort display name for a tool that has no entry yet: "notes_list" becomes "List".
     * Public so the contract test can tell a mapped title from a fallback one.
     *
     * @param string $name technical tool name
     */
    public static function humanized(string $name): string {
        $parts = array_slice(explode('_', $name), 1);
        $words = implode(' ', $parts === [] ? [$name] : $parts);
        return ucfirst($words);
    }
}