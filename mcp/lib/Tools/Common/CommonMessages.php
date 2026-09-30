<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

/**
 * User-facing strings of the classes in Tools/Common, which every module shares.
 *
 * The confirmation sentences live here and not in SharedWriteGuard so that Files, Notes, Deck and Calendar
 * cannot drift apart: the whole point of the shared guard is that the agent sees byte-identical JSON and
 * wording whichever module answered. When IL10N arrives, this is the class to translate.
 */
final class CommonMessages {
    /** Used when a share carries no sharer at all, so there is no uid to name. */
    public const UNIDENTIFIED = 'não identificado';
    /** Suffix shared by every confirmation message, in every module. */
    public const CONFIRM_ADVICE = 'Alterações afetam outras pessoas. Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.';
    /**
     * Same advice for the checkout upload, where the call cannot simply be repeated: the link is single
     * use and is already spent by the time the confirmation is produced.
     */
    public const CONFIRM_ADVICE_CHECKOUT = 'Alterações afetam outras pessoas. Confirme com o usuário antes de continuar e faça um novo files_checkout com confirm_shared: true, porque este link de edição já foi gasto.';

    /**
     * @param string $teamFolder name of the team folder the node lives in
     * @return string the sentence naming a team folder
     */
    public static function teamFolder(string $teamFolder): string {
        return sprintf('Este arquivo está na pasta de time "%s" (Team Folder).', $teamFolder);
    }

    /**
     * @param string $sharedBy display name of whoever shared the node
     * @return string the sentence naming the sharer
     */
    public static function sharedBy(string $sharedBy): string {
        return sprintf('Este arquivo foi compartilhado por %s.', $sharedBy);
    }

    /** @return string the sentence for a node in an external storage, which has no person to name */
    public static function externalStorage(): string {
        return 'Este arquivo está em um armazenamento externo.';
    }
}
