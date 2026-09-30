<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Every user-facing string of the calendar write path, in one place.
 *
 * The Phase 2 translation pass converts these constants and static methods mechanically, so no
 * handler carries a literal of its own.
 */
final class CalendarMessages {
    // ---- Tool descriptions shown in tools/list ----

    public const TOOL_LIST_CALENDARS = 'Lista os calendários visíveis ao usuário.';
    public const TOOL_LIST_EVENTS = 'Lista eventos num intervalo (default: próximos 7 dias).';
    public const TOOL_CREATE_EVENT = 'Cria um evento simples (sem recorrência) num calendário com permissão de escrita.';
    public const TOOL_UPDATE_EVENT = 'Altera título, local, descrição, datas ou participantes de um evento. Datas de série recorrente não podem ser alteradas.';
    public const TOOL_DELETE_EVENT = 'Exclui um evento (a série inteira), que vai para a lixeira do calendário. Exige confirm: true.';
    public const TOOL_MOVE_EVENT = 'Move um evento para outro calendário do mesmo dono, sem sobrescrever.';
    public const TOOL_TRANSFER_EVENT = 'Transfere um evento para um calendário de outro usuário compartilhado com você com permissão de escrita. Exige confirm: true. Eventos com participantes são recusados: o organizador continua sendo você e o Nextcloud não avisa ninguém nessa operação.';

    // ---- Fixed property descriptions of the schema helpers ----

    public const PROP_UID = 'UID do evento';
    public const PROP_ETAG = 'etag esperado; se divergir, nada é alterado';
    public const PROP_CONFIRM = 'precisa ser true para confirmar a operação';
    public const PROP_ATTENDEES = 'IDs de conta do Nextcloud (não e-mails). Substitui a lista de participantes do evento.';
    public const PROP_SEND_INVITATIONS = 'true entrega o convite ao agendamento do Nextcloud; o e-mail depende da configuração do servidor e o envio não confirma recebimento';

    // ---- Path building ----

    /** Object URI segment. */
    public const PATH_OBJECT = 'objeto';
    /** Calendar URI segment. */
    public const PATH_CALENDAR = 'calendário';
    /** UID segment. */
    public const PATH_USER = 'usuário';

    // ---- Errors returned as MCP isError results ----

    /** The ETag sent no longer matches the event. */
    public const CONFLICT_ETAG = 'o evento foi alterado por outra pessoa (etag divergente).';
    /** The UID is already taken in the calendar. */
    public const CONFLICT_UID = 'já existe um evento com o mesmo UID no calendário de destino.';
    /** The object URI or destination name is already taken. */
    public const CONFLICT_NAME = 'já existe um evento com o mesmo nome no calendário de destino.';
    /** Source and destination are the same calendar. */
    public const CONFLICT_SAME_CALENDAR = 'origem e destino são o mesmo calendário.';
    /** The move would cross owners; the transfer tool is the one for that. */
    public const MOVE_NEEDS_TRANSFER = 'Os calendários têm donos diferentes; use calendar_transfer_event.';
    /** The transfer stayed within one owner; the move tool is the one for that. */
    public const TRANSFER_NEEDS_MOVE = 'Os calendários têm o mesmo dono; use calendar_move_event.';

    /** The ICS was refused by the Nextcloud validation. */
    public const DATA_REFUSED = 'O Nextcloud recusou os dados do evento.';
    /** The event has data the tools cannot parse, so it is not touched. */
    public const EVENT_UNREADABLE = 'O evento tem dados inválidos e não pode ser alterado.';

    // ---- Notes appended to a successful result ----

    /** Move and transfer never schedule a message, exactly like the Calendar app. */
    public const PARTICIPANTS_NOT_NOTIFIED = 'Os participantes do evento não são avisados, como ao mover no app Calendar.';
    /** Invite delivery was requested but the server may not send e-mail. */
    public const IMIP_DISABLED = 'o servidor está com o envio de convites por e-mail desligado';
    /** Invite delivery was handed to the Nextcloud scheduler; no delivery is claimed. */
    public const SCHEDULING_HANDED_OVER = 'convite entregue ao agendamento do Nextcloud';
    /** Invitations were suppressed on purpose. */
    public const SCHEDULING_SUPPRESSED = 'nenhum convite foi agendado';
    /** Inviting people is a write with an effect on third parties. */
    public const INVITES_OTHERS = ' Esta chamada pode enviar convites a outras pessoas.';
    /** Suppression is the default; sending has to be asked for. */
    public const SEND_INVITATIONS_NOTE = ' Os participantes só são avisados com send_invitations: true, conforme a configuração do servidor; o envio não confirma recebimento.';

    /** Trash is disabled, so the deletion could not be undone. */
    public const TRASH_DISABLED = 'Exclusão bloqueada: a lixeira do calendário está desativada e o evento não poderia ser recuperado.';
    /** The recurring series cannot take a timing change through this path. */
    public const RECURRING_TIMING = 'alterar datas de uma série recorrente não é suportado.';
    /** Guests may not be invited from a recurring series. */
    public const RECURRING_ATTENDEES = 'os participantes não podem ser alterados em uma série recorrente.';
    /** Only the organizer may change the guest list. */
    public const NOT_ORGANIZER = 'só o organizador do evento pode alterar os participantes.';
    /** The move could not be confirmed by re-reading the calendars. */
    public const MOVE_NOT_CONFIRMED = 'Não foi possível mover o evento; tente novamente.';

    /** The transfer would leave the event with another organizer and no guest notice. */
    public const TRANSFER_WITH_ATTENDEES = 'Não é possível transferir um evento com participantes para o calendário de outra pessoa: o organizador continua sendo você e o Nextcloud não avisa ninguém nessa operação. Mova o evento dentro dos seus calendários ou remova os participantes antes.';
    /** Invitation sending was not proven on this server. */
    public const INVITATIONS_UNVERIFIED = 'envio de convites ainda não verificado neste servidor';

    /** Attendee list is empty, empty or above the cap. */
    public const ATTENDEES_INVALID = 'Informe de 1 a 50 participantes, sem repetição.';
    /** An attendee UID cannot be resolved to an internal account with an e-mail; %s is the UID. */
    public const ATTENDEE_NOT_FOUND = 'participante \'%s\' não encontrado ou sem endereço de e-mail';
    /** The organizer account has no e-mail, so an invited event could not be scheduled. */
    public const ORGANIZER_WITHOUT_EMAIL = 'a sua conta não tem endereço de e-mail, então o evento não pode ter participantes.';

    // ---- Failure messages of the existing calendar classes ----

    /** Calendar or event missing, or not visible to the user. */
    public const NOT_FOUND = 'Calendário ou evento não encontrado.';
    /** The user cannot write to the resource. */
    public const FORBIDDEN = 'Sem permissão para alterar este calendário ou evento.';
    /** end must be after start. */
    public const RANGE_END_BEFORE_START = 'Intervalo inválido: end deve ser posterior a start.';
    /** from must be before to. */
    public const RANGE_FROM_AFTER_TO = 'Intervalo inválido: from deve ser anterior a to.';
    /** An ISO value the tools cannot parse; %s is the argument name. */
    public const INVALID_ISO_DATE = 'Data ISO inválida em %s.';
    /** An all-day value that is not a plain date; %s is the argument name. */
    public const INVALID_ALL_DAY_FORMAT = 'Use o formato AAAA-MM-DD em %s para evento de dia inteiro.';
    /** A time zone that is not a known IANA name. */
    public const INVALID_TIME_ZONE = 'Fuso horário inválido em timeZone.';
    /** A listing window larger than the accepted 366 days. */
    public const WINDOW_TOO_WIDE = 'Janela do calendário excede o limite de 366 dias.';
    /** A series expanded to more occurrences than one call returns. */
    public const OCCURRENCE_LIMIT = 'Limite de 500 ocorrências por série atingido; reduza a janela do calendário.';
    /** The update call changed nothing. */
    public const NO_FIELD_GIVEN = 'Informe ao menos um campo para alterar.';
    /** allDay was flipped without both dates. */
    public const ALL_DAY_NEEDS_DATES = 'Informe start e end ao alterar allDay.';

    // ---- Fragments shared by the tool descriptions ----

    /** Sentence asking the agent to confirm a write on somebody else's calendar; %s and %s are the calendar name and the owner's display name. */
    public const SHARED_CONFIRMATION = "O calendário '%s' pertence a %s e é compartilhado com você. Alterações afetam outras pessoas. Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.";
    /** Sentence appended to the description of every write this guard protects. */
    public const SHARED_CONFIRMATION_SUFFIX = ' Se o recurso for de outra pessoa, o agente DEVE perguntar ao usuário antes de enviar confirm_shared: true.';
    /** Description of the confirm_shared property. */
    public const CONFIRM_SHARED_PROPERTY = 'marque como true apenas depois que o usuário confirmar a alteração em um calendário de outra pessoa';

    /** Generic failure of the DAV pipeline, reported without internals. */
    public const DAV_FAILURE = 'Calendar DAV failure';

    private function __construct() {
    }

    /**
     * @param int $limit configured size limit in bytes
     * @return string the event is larger than the server accepts
     */
    public static function eventTooLarge(int $limit): string {
        return 'O evento passou do limite de ' . $limit . ' bytes que o servidor aceita.';
    }

    /**
     * @param string $code SCHEDULE-STATUS base code reported by the Nextcloud scheduler
     * @return string what that status means for the caller
     */
    public static function scheduleStatus(string $code): string {
        return match (true) {
            str_starts_with($code, '1.1') => 'entregue ao envio de e-mail do Nextcloud; o recebimento não é confirmado',
            str_starts_with($code, '1.2') => 'entregue na agenda do participante interno',
            str_starts_with($code, '1.0') => 'mudança sem relevância; nada enviado',
            str_starts_with($code, '3'), str_starts_with($code, '5') => 'falha no envio (' . $code . ')',
            default => 'sem registro de envio',
        };
    }

    /**
     * @param string $identifier refused path segment
     * @param string $label Portuguese noun of the segment
     * @return string the message of the refusal
     */
    public static function invalidPathSegment(string $label): string {
        return 'Identificador de ' . $label . ' inválido.';
    }
}