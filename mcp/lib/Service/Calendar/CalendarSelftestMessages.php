<?php
declare(strict_types=1);
namespace OCA\Mcp\Service\Calendar;

/** Messages kept together for the later translation pass. */
final class CalendarSelftestMessages {
    public const ASSERTION_PREFIX = 'Efeito não confirmado:';
    public const DESCRIPTION = 'Verifica as escritas Calendar pelo DAV real e libera o gate após a limpeza.';
    public const UID = 'UID interno do organizador do teste (conta habilitada com e-mail e Calendar).';
    public const ATTENDEE = 'Outro UID interno: prova iTIP na agenda e caixa CalDAV; pode gerar e-mail conforme dav/sendInvitations.';
    public const SHARED = 'Path de calendário de outro dono compartilhado com escrita; prova transfer e exclui só o evento de teste.';
    public const ACL = 'Outro UID sem acesso aos calendários de teste; prova recusa de escrita.';
    public const NO_ENABLE = 'Executa a prova e a limpeza sem gravar a liberação; revoga a liberação anterior.';
    public const REVOKE = 'Apaga a verificação e oculta todas as escritas, sem criar objetos.';
    public const REVOKED = 'Verificação revogada; escritas Calendar ocultas.';
    public const INVALID_USER = 'A conta precisa existir, estar habilitada, ter e-mail e ter o app Calendar habilitado.';
    public const INVALID_ATTENDEE = 'O participante e o usuário da prova ACL precisam ser contas internas habilitadas, diferentes do organizador.';
    public const TRASH_REQUIRED = 'A retenção do Calendar está em 0. O teste foi recusado antes de criar objetos porque a limpeza seria permanente.';
    public const FAILED = 'A verificação falhou; nenhuma escrita foi liberada.';
    public const PASSED = 'Prova concluída e limpeza confirmada; verificação gravada.';
    public const PASSED_NO_ENABLE = 'Prova concluída e limpeza confirmada; verificação não gravada (--no-enable).';
    public const EVENT = 'MCP Calendar selftest';
    public const EVENT_EDITED = 'MCP Calendar selftest editado';
    public const OPTIONAL = 'Opção não fornecida; capacidade não verificada.';
    public const SUMMARY = 'Confirme Activity, clientes CalDAV e recebimento de e-mail manualmente; o relatório não confirma entrega de e-mail.';

    /** Assertion labels shown by the proof, keyed by internal identifiers. */
    private const CHECKS = [
        'shared-owner' => 'calendário compartilhado de outro dono',
        'fresh-uri' => 'URI novo',
        'mkcalendar-status' => 'MKCALENDAR 201',
        'created-owner' => 'dono do calendário criado',
        'created-etag-sync' => 'ETag e sync-token após create',
        'edited-summary-sequence' => 'SUMMARY e SEQUENCE após update',
        'tool-conflict-unchanged' => 'objeto intacto após conflito na tool',
        'dav-conflict-unchanged' => 'objeto intacto após If-Match',
        'move-presence-sync' => 'origem ausente, destino presente e sync-token nas duas agendas',
        'overwrite-unchanged' => 'origem e destino intactos após Overwrite F',
        'deleted-object' => 'objeto na lixeira e fora da leitura',
        'internal-status' => 'SCHEDULE-STATUS 1.2 no participante interno',
        'internal-copy' => 'cópia na agenda do participante',
        'internal-inbox' => 'iTIP REQUEST na caixa CalDAV',
        'acl-unchanged' => 'ACL recusou sem gravar',
        'reserved-uri' => 'URI reservado nesta execução',
        'cleanup-identity' => 'identidade do calendário antes da limpeza',
        'trashed-calendar' => 'calendário na lixeira após DELETE',
        'live-object' => 'objeto vivo por UID',
        'live-sync' => 'sync-token vivo',
        'attendee-preserved' => 'ATTENDEE preservado',
        'suppressed' => 'supressão de scheduling',
        'refusal-status' => 'status HTTP da recusa',
        'refusal' => 'operação recusada',
        'participant-cleanup' => 'limpeza do participante',
    ];

    /** @return string safe stage evidence assembled from generated identifiers only */
    public static function detail(string $step, mixed ...$values): string {
        $format = match ($step) {
            'calendars' => 'HTTP 201; %s %s',
            'create' => 'HTTP 201; UID %s; sync-token %s',
            'suppression-create' => 'HTTP %s; ATTENDEE @example.invalid sem SCHEDULE-STATUS',
            'suppression-update' => 'HTTP 204; SEQUENCE subiu; ATTENDEE sem SCHEDULE-STATUS',
            'stale-tool' => 'Conflito antes do dispatch; dados e ETag intactos',
            'stale-dav' => 'HTTP 412; If-Match recusado; dados e ETag intactos',
            'move' => 'HTTP 201; UID no destino; sync-token das duas agendas subiu',
            'overwrite' => 'HTTP 412; Overwrite F; dois objetos intactos',
            'delete' => 'HTTP 204; deleted=true; UID fora da leitura',
            'invitations' => 'HTTP 204; SCHEDULE-STATUS 1.2; cópia e iTIP REQUEST confirmados',
            'transfer' => 'HTTP 201/204; UID transferido e excluído na agenda compartilhada',
            'acl' => 'HTTP 403/404; nada gravado',
            'cleanup' => 'HTTP 204; recursos desta execução removidos; sessão restaurada',
            'cleanup-empty' => 'Nenhum recurso criado; sessão restaurada',
        };
        return sprintf($format, ...$values);
    }

    /** @return string human-readable assertion that failed, without backend event content */
    public static function assertion(string $check): string {
        return self::ASSERTION_PREFIX . ' ' . self::CHECKS[$check] . '.';
    }

    /** @return string one report line; details are generated identifiers or safe errors only */
    public static function line(array $step): string {
        return $step['status'] . ' ' . $step['step'] . ': ' . $step['detail'];
    }
}
