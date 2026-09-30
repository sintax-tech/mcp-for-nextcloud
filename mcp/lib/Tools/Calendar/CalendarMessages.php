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