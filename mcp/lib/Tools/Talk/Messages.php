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
    public const TOOL_REPLY = 'Envia uma mensagem em uma conversa, opcionalmente citando outra mensagem da mesma conversa com reply_to. Para apontar um card do Deck ou um evento do Calendar, informe reference com {type: deck_card, card_id, board_id opcional} ou {type: calendar_event, calendar (o path de calendar_list_calendars), uid}: o servidor confere que o usuário tem acesso ao item e acrescenta ao texto o título e o link do item, e o rascunho já mostra o texto final. Quem não tiver acesso ao item na conversa verá o título e o link, então só referencie o que o usuário aprovar mostrar. Antes de enviar, o agente DEVE chamar a tool sem confirm, mostrar o rascunho devolvido ao usuário e obter aprovação explícita; só então repetir com confirm: true e o approval_id da prévia. O servidor só publica com uma prévia válida, de uso único e com validade de 10 minutos; a aprovação em si depende de o agente mostrar o rascunho e de o usuário confirmar no cliente.';
    public const TOOL_ATTACH_FILE = 'Compartilha na conversa um arquivo que já existe no Nextcloud do usuário; o cartão do anexo é publicado pelo próprio Talk. Quando message é preenchida, ela é enviada como uma segunda mensagem de texto logo após o anexo, e messageId traz o id dessa mensagem. Se o arquivo já estiver compartilhado nesta conversa, nada é publicado. Se a partilha for criada mas a legenda falhar, a resposta não é erro: vem attachmentId com captionSent false e a instrução de enviar a legenda com talk_reply, porque o anexo já está no chat. Antes de enviar, o agente DEVE chamar a tool sem confirm, mostrar o rascunho devolvido ao usuário e obter aprovação explícita; só então repetir com confirm: true e o approval_id da prévia. O servidor só publica com uma prévia válida, de uso único e com validade de 10 minutos; a aprovação em si depende de o agente mostrar o rascunho e de o usuário confirmar no cliente.';
    public const TOOL_QUOTE_FILE = 'Publica uma mensagem citando um anexo já presente na conversa, identificado por attachment_id, com legenda opcional em message. Antes de enviar, o agente DEVE chamar a tool sem confirm, mostrar o rascunho devolvido ao usuário e obter aprovação explícita; só então repetir com confirm: true e o approval_id da prévia. O servidor só publica com uma prévia válida, de uso único e com validade de 10 minutos; a aprovação em si depende de o agente mostrar o rascunho e de o usuário confirmar no cliente.';

    public const TOOL_MESSAGE_USER = 'Envia uma mensagem direta para um usuário, sem precisar do token da conversa: informe user com o id da conta. A conversa direta é criada se ainda não existir, como o Talk faz ao iniciar uma conversa; a prévia nunca cria nada. Antes de enviar, o agente DEVE chamar a tool sem confirm, mostrar o rascunho devolvido ao usuário e obter aprovação explícita; só então repetir com confirm: true e o approval_id da prévia. O servidor só publica com uma prévia válida, de uso único e com validade de 10 minutos; a aprovação em si depende de o agente mostrar o rascunho e de o usuário confirmar no cliente.';

    public const TOOL_SEND_BATCH = 'Envia várias mensagens de uma vez na mesma conversa, cada uma com a citação opcional de uma mensagem anterior: informe messages com a lista de objetos {message, reply_to}. Use quando a resposta for uma sequência de mensagens curtas (uma por item de uma lista, por exemplo), e prefira talk_reply quando for uma única mensagem. A conversa e as citações são verificadas antes de qualquer envio; o envio acontece item a item e cada item é reportado em sent ou failed, sem repetir o que já foi publicado. Antes de enviar, o agente DEVE chamar a tool sem confirm, mostrar o rascunho devolvido ao usuário e obter aprovação explícita; só então repetir com confirm: true e o approval_id da prévia. O servidor só publica com uma prévia válida, de uso único e com validade de 10 minutos; a aprovação vale para o lote inteiro, não para cada item, e a aprovação em si depende de o agente mostrar o rascunho e de o usuário confirmar no cliente.';

    public const TOOL_CREATE_GROUP = 'Cria uma conversa em grupo no Talk, com o usuário autenticado como dono: informe name com o nome do grupo e participants com a lista de contas a convidar (opcional). Cada convidado passa pela mesma regra de visibilidade que uma mensagem direta, e todos aparecem na prévia antes de qualquer convite. Criar um grupo convida pessoas e esta ferramenta não desfaz o convite depois de feito, então a operação exige o grant talk.create e aprovação explícita. Antes de enviar, o agente DEVE chamar a tool sem confirm, mostrar o rascunho devolvido ao usuário e obter aprovação explícita; só então repetir com confirm: true e o approval_id da prévia. O servidor só cria a sala com uma prévia válida, de uso único e com validade de 10 minutos; a aprovação vale para o grupo inteiro, nome e convidados juntos, e a aprovação em si depende de o agente mostrar o rascunho e de o usuário confirmar no cliente. Quem não aprovar não fica com grupo vazio na lista: a sala só é criada na chamada aprovada. Os convites são feitos um a um depois da criação: quem entrou vem em participants e quem o Talk recusou vem em invitations_failed, com o id de cada um; nesse caso o grupo já existe, então não repita a chamada, informe ao usuário quem ficou de fora.';

    /** Instruction shown next to a group draft, where %s is the name of the group. */
    public const CONFIRMATION_INSTRUCTION_GROUP = 'Vou criar no Talk o grupo \'%s\' com você como dono%s. Mostre isso ao usuário e só repita a mesma chamada com confirm: true e approval_id igual ao approvalId acima depois que ele aprovar; se ele pedir mudanças, mude o rascunho e gere outra prévia. Sem essa aprovação o servidor não cria nada.';

    /** Instruction shown next to a draft, where %s is the conversation name. */
    public const CONFIRMATION_INSTRUCTION = 'Mostre este rascunho ao usuário exatamente como será enviado para a conversa \'%s\' e só repita a mesma chamada com confirm: true e approval_id igual ao approvalId acima depois que ele aprovar. Se ele pedir mudanças, ajuste o texto, chame a tool de novo para gerar outra prévia e mostre de novo. Sem essa aprovação o servidor não publica nada.';

    /** Instruction shown next to a direct message draft, where %s is the name of the person being written to. */
    public const CONFIRMATION_INSTRUCTION_TARGET = 'Mostre este rascunho ao usuário exatamente como será enviado em uma conversa direta com \'%s\' e só repita a mesma chamada com confirm: true e approval_id igual ao approvalId acima depois que ele aprovar. Se ele pedir mudanças, ajuste o texto, chame a tool de novo para gerar outra prévia e mostre de novo. Sem essa aprovação o servidor não publica nada e a conversa direta não é criada.';

    /** Label of the line a reference adds to a message, before the title of the item. */
    public const REFERENCE_LABEL_DECK = 'Card do Deck';
    public const REFERENCE_LABEL_CALENDAR = 'Evento do Calendar';
    /** Title shown for an event without SUMMARY. */
    public const REFERENCE_UNTITLED = '(sem título)';

    // Failure messages returned as MCP result with isError.
    public const CONVERSATION_NOT_FOUND = 'conversa não encontrada ou sem acesso';
    public const CONVERSATION_NOT_WRITABLE = 'sem permissão para escrever nesta conversa';
    public const FILE_NOT_FOUND = 'arquivo não encontrado ou sem acesso';
    public const ATTACHMENT_NOT_FOUND = 'anexo não encontrado nesta conversa';
    public const USER_NOT_REACHABLE = 'usuário não encontrado ou sem permissão para falar com ele';
    /** Refusal of one guest of a group, where %s is the account id the caller sent. */
    public const PARTICIPANT_NOT_REACHABLE = 'participante \'%s\' não encontrado ou sem permissão para falar com ele';
    /** Reported per guest in invitations_failed, after the group already exists. */
    public const INVITATION_FAILED = 'o Talk não aceitou o convite deste participante';
    public const REFERENCE_NOT_FOUND = 'item referenciado não encontrado ou sem acesso';
    public const REFERENCE_DECK_OFF = 'referência ao Deck indisponível: o app Deck está desligado para você ou o MCP não tem permissão de leitura no Deck';
    public const REFERENCE_CALENDAR_OFF = 'referência ao Calendar indisponível: o app Calendar está desligado para você ou o MCP não tem permissão de leitura no Calendar';
    public const CONVERSATION_NOT_CREATED = 'conversa não criada pelo Talk';
    public const MESSAGE_NOT_SENT = 'não foi possível enviar a mensagem';
    public const FILE_NOT_SHARED = 'não foi possível compartilhar o arquivo';
    public const FILE_ALREADY_SHARED = 'o arquivo já está compartilhado nesta conversa';
    public const CAPTION_NOT_SENT = 'O anexo foi compartilhado nesta conversa, mas a legenda não foi enviada. Não repita talk_attach_file: o arquivo já está compartilhado e um novo cartão não seria criado. Se o usuário ainda quiser a legenda, envie-a como uma nova mensagem com talk_reply, seguindo a mesma aprovação.';
    public const TALK_UNAVAILABLE = 'o app de conversas não está disponível';
    public const APPROVAL_MISSING = 'falta a aprovação do rascunho: chame a tool sem confirm para receber a prévia e mostre-a ao usuário antes de repetir com confirm: true';
    public const APPROVAL_INVALID = 'aprovação inválida, expirada ou já usada: gere a prévia de novo, mostre-a ao usuário e repita com o novo approval_id';
    public const UNEXPECTED = 'erro inesperado ao acessar o Talk';

    // Argument messages returned as -32602 through InvalidArgumentException.
    public const INVALID_TOKEN = 'conversation_token inválido';
    public const EMPTY_MESSAGE = 'message não pode ser vazia';
    public const EMPTY_BATCH = 'informe ao menos uma mensagem em messages';
    public const TOO_MANY_MESSAGES = 'lote com mais de %d mensagens';
    public const MESSAGE_TOO_LONG = 'message excede o limite de caracteres';
    public const INVALID_PATH = 'path inválido';
    public const INVALID_USER = 'user inválido';
    public const INVALID_REFERENCE = 'reference inválida: use {type: deck_card, card_id, board_id?} ou {type: calendar_event, calendar, uid}';
    public const INVALID_GROUP_NAME = 'name do grupo não pode ser vazio';
    public const GROUP_NAME_TOO_LONG = 'name do grupo excede o limite de caracteres';
    public const TOO_MANY_PARTICIPANTS = 'grupo com mais de %d participantes';
    public const INVALID_IDENTIFIER = 'identificador inválido';
    public const INVALID_LIMIT = 'limit deve ser um número entre 1 e 200';
    public const REPLY_TARGET_NOT_FOUND = 'mensagem citada não encontrada nesta conversa';
    public const UNKNOWN_TOOL = 'ferramenta desconhecida';

    private function __construct() {
    }
}
