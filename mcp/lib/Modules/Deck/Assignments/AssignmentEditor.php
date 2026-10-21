<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Assignments;

use OCA\Mcp\Modules\Deck\Execution\VerifiedWrite;
use Psr\Log\LoggerInterface;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Deck\Execution\DeckErrors;

class AssignmentEditor {
    public function __construct(
        private VerifiedWrite $writes,
        private AssignmentAccess $assignments,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Assigns and unassigns one account at a time and reports what happened.
     *
     * A failure is recorded and the loop goes on: nothing already done is undone, and the card is
     * mutated in place only for what succeeded, so the payload shows the real assignees.
     *
     * @param string $userId UID of the authenticated caller.
     * @param Card $card Card to report, its assignments updated as each change succeeds.
     * @param array{add: list<string>, remove: list<string>, alreadyAssigned: list<string>, notAssigned: list<string>} $resolved What to do and what to ignore.
     * @param list<string> $warnings Warnings so far (a saved update whose Deck side effects failed); each change that
     *     Deck saved before failing adds the same warning once, and the change counts as done.
     * @return array{assigned: list<string>, unassigned: list<string>, failed: list<array{uid: string, reason: string}>, warnings?: list<string>}
     */
    public function applyAssignees(
        string $userId,
        Card $card,
        array $resolved,
        array $warnings = [],
    ): array {
        $assigned = [];
        $unassigned = [];
        $failed = [];
        $cardId = (int) $card->getId();
        $assignments = $card->getAssignedUsers() ?? [];
        foreach ($resolved['remove'] as $uid) {
            try {
                $this->writes->afterWrite(
                    'deck_edit_card',
                    'deck_read_card',
                    fn() => $this->assignments->unassignCardUser($userId, $cardId, $uid),
                    fn() => $this->assignments->assignmentOf($userId, $cardId, $uid) === null ? true : null,
                    $warnings,
                );
                $unassigned[] = $uid;
                $assignments = array_values(array_filter(
                    $assignments,
                    static fn($assignment): bool => !($assignment->getType() === Acl::PERMISSION_TYPE_USER && (string) $assignment->getParticipant() === $uid),
                ));
            } catch (\Throwable $e) {
                $failed[] = $this->failure($uid, $e);
            }
        }
        foreach ($resolved['add'] as $uid) {
            try {
                $assignments[] = $this->writes->afterWrite(
                    'deck_edit_card',
                    'deck_read_card',
                    fn() => $this->assignments->assignCardUser($userId, $cardId, $uid),
                    fn() => $this->assignments->assignmentOf($userId, $cardId, $uid),
                    $warnings,
                );
                $assigned[] = $uid;
            } catch (\Throwable $e) {
                $failed[] = $this->failure($uid, $e);
            }
        }
        $card->setAssignedUsers($assignments);
        if ($resolved['notAssigned'] !== []) {
            $warnings[] = Translator::t('Ignored, not assigned to the card: %s', [implode(', ', $resolved['notAssigned'])]);
        }
        if ($resolved['alreadyAssigned'] !== []) {
            $warnings[] = Translator::t('Ignored, already assigned to the card: %s', [implode(', ', $resolved['alreadyAssigned'])]);
        }
        return ['assigned' => $assigned, 'unassigned' => $unassigned, 'failed' => $failed] + ($warnings === [] ? [] : ['warnings' => $warnings]);
    }

    /**
     * @param string $uid Account whose change failed.
     * @param \Throwable $e What Deck raised; only its class is logged, never its message.
     * @return array{uid: string, reason: string} Entry of `failed`, worded by {@see DeckErrors}.
     */
    private function failure(string $uid, \Throwable $e): array {
        $this->logger->warning('MCP Deck assignment change failed ({exception})', ['exception' => $e::class]);
        return ['uid' => $uid, 'reason' => DeckErrors::messageFor($e)];
    }
}
