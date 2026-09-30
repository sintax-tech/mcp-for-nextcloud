<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

/**
 * Every user-facing string of the Talk module lives here, so the app can become multilingual (IL10N) later
 * without touching the handlers. Keep them in Portuguese for now.
 */
final class Messages {
    // Descriptions of the tools shown in tools/list.
    public const TOOL_LIST_CONVERSATIONS = 'Lista as conversas de Talks em que o usuário autenticado participa, com contagem de mensagens não lidas. Não altera estado: não marca como lido, não muda notificações e não muda atividade.';
    public const TOOL_READ_MESSAGES = 'Lê as últimas mensagens de uma conversa, sem marcar como lido, sem ler notificações e sem registrar atividade. Use o id retornado em reply_to para responder e o attachmentId para citar um anexo.';
    public const TOOL_REPLY = 'Envia uma mensagem em uma conversa, opcionalmente citando outra mensagem da mesma conversa com reply_to.';
    public const TOOL_ATTACH_FILE = 'Compartilha na conversa um arquivo que já existe no Nextcloud do usuário e publica a mensagem do anexo, com legenda opcional em message.';
    public const TOOL_QUOTE_FILE = 'Publica uma mensagem citando um anexo já presente na conversa, identificado por attachment_id, com legenda opcional em message.';

    // Failure messages returned as MCP result with isError.
    public const CONVERSATION_NOT_FOUND = 'conversa não encontrada ou sem acesso';
    public const CONVERSATION_NOT_WRITABLE = 'sem permissão para escrever nesta conversa';
    public const FILE_NOT_FOUND = 'arquivo não encontrado ou sem acesso';
    public const ATTACHMENT_NOT_FOUND = 'anexo não encontrado nesta conversa';
    public const MESSAGE_NOT_SENT = 'não foi possível enviar a mensagem';
    public const FILE_NOT_SHARED = 'não foi possível compartilhar o arquivo';
    public const FILE_ALREADY_SHARED = 'o arquivo já está compartilhado nesta conversa';
    public const TALK_UNAVAILABLE = 'o app de conversas não está disponível';
    public const UNEXPECTED = 'erro inesperado ao acessar o Talk';

    // Argument messages returned as -32602 through InvalidArgumentException.
    public const INVALID_TOKEN = 'conversation_token inválido';
    public const EMPTY_MESSAGE = 'message não pode ser vazia';
    public const MESSAGE_TOO_LONG = 'message excede o limite de caracteres';
    public const INVALID_PATH = 'path inválido';
    public const INVALID_IDENTIFIER = 'identificador inválido';
    public const REPLY_TARGET_NOT_FOUND = 'mensagem citada não encontrada nesta conversa';

    private function __construct() {
    }
}
