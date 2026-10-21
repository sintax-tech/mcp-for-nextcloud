<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Execution\VerifiedWrite;
use OCA\Mcp\Modules\Deck\Cards\CardWriter;
use OCA\Mcp\Modules\Deck\Cards\CardStateReader;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Modules\Deck\Execution\WriteMessages;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Deck\Execution\DeckErrors;
use OCA\Mcp\Modules\Deck\Execution\DeckUnconfirmedException;

class BlueprintProvisioner {
    public function __construct(
        private VerifiedWrite $writes,
        private StackCreator $stackCreator,
        private StructureStateReader $structureState,
        private CardWriter $writer,
        private CardStateReader $state,
        private AssignmentAccess $assignments,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Creates the lists and the cards of an existing board, collecting what could not be created.
     *
     * @param int $boardId Board just created.
     * @param string $boardTitle Its title as Deck stored it.
     * @param array{stacks: list<array{title: string, cards: list<array{title: string, description: string, duedate: string|null, assignees: list<string>}>}>} $blueprint Validated structure.
     * @param string $userId UID of the authenticated caller.
     * @param list<string> $warnings Warnings so far, such as a board saved before Deck failed.
     * @param bool $probableBoard Whether the board was found again only by its title: nothing is built inside it.
     * @return array<string, mixed> Payload that says what exists and what does not.
     */
    public function build(
        int $boardId,
        string $boardTitle,
        array $blueprint,
        string $userId,
        array $warnings = [],
        bool $probableBoard = false,
    ): array {
        $stacks = [];
        $failed = [];
        $unconfirmed = 0;
        $probables = $probableBoard ? 1 : 0;
        $cardTotal = 0;
        foreach ($blueprint['stacks'] as $position => $wanted) {
            if ($probableBoard) {
                // A board that may be somebody else's call: no list is written in it.
                $failed[] = [
                    'kind' => 'list',
                    'title' => $wanted['title'],
                    'reason' => WriteMessages::probableParentNotBuilt(),
                    'cardsNotCreated' => count($wanted['cards']),
                ];
                continue;
            }
            $probableStack = false;
            try {
                $known = array_column($stacks, 'id');
                $stack = $this->writes->afterApproximateWrite(
                    'deck_create_board',
                    'deck_list_boards',
                    fn() => $this->stackCreator->createStack($userId, $boardId, $wanted['title'], $position),
                    fn() => StructureStateReader::newStack($this->structureState->stacksOf($userId, $boardId), $wanted['title'], $known),
                    $warnings,
                    $probableStack,
                );
            } catch (DeckUnconfirmedException $e) {
                $warnings[] = $this->unconfirmed('list', $wanted['title'], $e);
                $unconfirmed++;
                continue;
            } catch (\Throwable $e) {
                $failed[] = [
                    'kind' => 'list',
                    'title' => $wanted['title'],
                    'reason' => $this->reason($e),
                    'cardsNotCreated' => count($wanted['cards']),
                ];
                continue;
            }
            $probables += $probableStack ? 1 : 0;
            $cards = [];
            foreach ($wanted['cards'] as $order => $card) {
                if ($probableStack) {
                    // A list that may be somebody else's call: no card is written in it.
                    $failed[] = [
                        'kind' => 'card',
                        'list' => $wanted['title'],
                        'title' => $card['title'],
                        'reason' => WriteMessages::probableParentNotBuilt(),
                    ];
                    continue;
                }
                $probableCard = false;
                try {
                    $known = array_column($cards, 'id');
                    $created = $this->writes->afterApproximateWrite(
                        'deck_create_board',
                        'deck_list_boards',
                        fn() => $this->writer->createCard($userId, (int) $stack->getId(), $card['title'], $card['description'], $card['duedate'], $order),
                        fn() => $this->state->findCreatedCard($userId, (int) $stack->getId(), $card['title'], $known),
                        $warnings,
                        $probableCard,
                    );
                } catch (DeckUnconfirmedException $e) {
                    $warnings[] = $this->unconfirmed('card', $card['title'], $e);
                    $unconfirmed++;
                    continue;
                } catch (\Throwable $e) {
                    $failed[] = [
                        'kind' => 'card',
                        'list' => $wanted['title'],
                        'title' => $card['title'],
                        'reason' => $this->reason($e),
                    ];
                    continue;
                }
                $cards[] = ['id' => (int) $created->getId(), 'title' => $card['title']] + ($probableCard ? ['confirmed' => 'probable'] : []);
                if ($probableCard) {
                    $probables++;
                    // Found by likeness, so maybe somebody else's card: nothing is written on it.
                    if ($card['assignees'] !== []) {
                        $warnings[] = WriteMessages::probableCardNotAssigned();
                    }
                    continue;
                }
                foreach ($card['assignees'] as $assignee) {
                    try {
                        $this->writes->afterWrite(
                            'deck_create_board',
                            'deck_list_boards',
                            fn() => $this->assignments->assignCardUser($userId, (int) $created->getId(), $assignee),
                            fn() => $this->assignments->assignmentOf($userId, (int) $created->getId(), $assignee),
                            $warnings,
                        );
                    } catch (\Throwable $e) {
                        // The card exists: never turn this into something that invites a second attempt.
                        $this->logger->warning('MCP Deck assignment failed after creation ({exception})', ['exception' => $e::class]);
                        $warnings[] = Translator::t(
                            'The card \'%s\' was created, but an assignee could not be assigned. Read the card before retrying in Deck.',
                            [$card['title']],
                        );
                    }
                }
            }
            $cardTotal += count($cards);
            $stacks[] = ['id' => (int) $stack->getId(), 'title' => $wanted['title']] + ($probableStack ? ['confirmed' => 'probable'] : []) + ['cards' => $cards];
        }
        $summary = $probableBoard ? WriteMessages::probablyCreated() : $this->summary($boardTitle, count($stacks), $cardTotal, count($failed));
        return [
            'created' => [
                'board' => ['id' => $boardId, 'title' => $boardTitle] + ($probableBoard ? ['confirmed' => 'probable'] : []),
                'stacks' => $stacks,
            ],
            'failed' => $failed,
            'warnings' => $warnings,
            'complete' => $failed === [] && $unconfirmed === 0 && $probables === 0,
            'summary' => $unconfirmed === 0 && $probables === 0 || $probableBoard ? $summary : Translator::t('%s Some items could not be confirmed; see \'warnings\' before retrying.', [$summary]),
        ];
    }

    /**
     * Warning for a list or card whose creation threw and could not be read back: maybe created, so not in `failed`.
     *
     * @param string $kind `list` or `card`.
     * @param string $title Its title.
     * @param DeckUnconfirmedException $e What the read-back raised; only the class of the original exception is logged.
     * @return string Caller-safe warning.
     */
    private function unconfirmed(
        string $kind,
        string $title,
        DeckUnconfirmedException $e,
    ): string {
        $this->logger->warning(
            'MCP Deck tool step not confirmed: {tool} ({exception})',
            [
                'tool' => 'deck_create_board',
                'exception' => $e->getPrevious() === null ? $e::class : $e->getPrevious()::class,
            ],
        );
        return WriteMessages::boardItemUnconfirmed($kind, $title);
    }

    /**
     * Safe reason of a failed step: logged by class, worded without any Deck detail.
     *
     * @param \Throwable $e What the capability raised.
     * @return string Caller-safe message.
     */
    private function reason(\Throwable $e): string {
        $this->logger->error(
            'MCP Deck tool step failed: {tool} ({exception})',
            ['tool' => 'deck_create_board', 'exception' => $e::class],
        );
        return DeckErrors::messageFor($e);
    }

    /**
     * One sentence that says what exists, so the model does not have to infer it from the lists.
     *
     * @param string $board Board title.
     * @param int $stacks Lists created.
     * @param int $cards Cards created.
     * @param int $failed Steps that failed.
     * @return string The sentence in the language of the user.
     */
    private function summary(
        string $board,
        int $stacks,
        int $cards,
        int $failed,
    ): string {
        $made = Translator::t(
            '%s and %s',
            [Translator::n('%n list', '%n lists', $stacks), Translator::n('%n card', '%n cards', $cards)],
        );
        if ($failed === 0) {
            return Translator::t('The board \'%s\' was created with %s.', [$board, $made]);
        }
        return Translator::t(
            'The board \'%s\' exists with %s, but %s could not be created. Nothing was undone; see \'failed\' before retrying.',
            [$board, $made, Translator::n('%n item', '%n items', $failed)],
        );
    }
}
