<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText as ProtocolPlanText;

class StructurePlanRenderer {
    /**
     * The tree of a board that will be built: lists in order, each with its cards and what is set on them.
     *
     * @param array<string, mixed> $plan
     */
    public static function renderCreateBoard(array $plan, \DateTimeZone $zone): ?string {
        $board = $plan['board'] ?? null;
        $stacks = $plan['stacks'] ?? null;
        if (!is_array($board) || !isset($board['title']) || !is_string($board['title']) || $board['title'] === '' || !is_array($stacks)) {
            return null;
        }
        $cards = 0;
        foreach ($stacks as $stack) {
            $cards += is_array($stack['cards'] ?? null) ? count($stack['cards']) : 0;
        }
        $lines = [Translator::t(
            'Create board **%s**: %s, %s.',
            [
                PlanText::name($board['title']),
                Translator::n('%n list', '%n lists', count($stacks)),
                Translator::n('%n card', '%n cards', $cards),
            ],
        )];
        // The colour is typed by the caller: it is printed only when it is exactly what Deck stores.
        if (!empty($board['colorGiven']) && is_string($board['color'] ?? null) && preg_match('/^[0-9a-f]{6}$/', $board['color']) === 1) {
            $lines[] = Translator::t('- Color: #%s', [$board['color']]);
        }
        foreach ($stacks as $stack) {
            if (!is_array($stack) || !is_string($stack['title'] ?? null)) {
                return null;
            }
            $lines[] = Translator::t('- List **%s**', [PlanText::name($stack['title'])]);
            foreach (is_array($stack['cards'] ?? null) ? $stack['cards'] : [] as $card) {
                if (!is_array($card) || !is_string($card['title'] ?? null)) {
                    return null;
                }
                $lines[] = self::boardCardLine($card, $zone);
            }
        }
        $lines[] = Translator::t('- The board is open only to you until you share it in Deck.');
        return implode("\n", $lines);
    }

    /**
     * One card of the tree: its title and, in brackets, what is set on it.
     *
     * @param array<string, mixed> $card Card of a list of the plan.
     */
    public static function boardCardLine(array $card, \DateTimeZone $zone): string {
        $details = [];
        $due = $card['duedate'] ?? null;
        if (is_string($due) && $due !== '') {
            $details[] = Translator::t('due %s', [PlanText::formatDate($due, $zone)]);
        }
        $assignees = PlanText::extractAssignees($card);
        if ($assignees !== []) {
            $details[] = Translator::t('assigned to %s', [ProtocolPlanText::inline(implode(', ', $assignees), 300)]);
        }
        if (is_string($card['description'] ?? null) && $card['description'] !== '') {
            $details[] = Translator::t('with description');
        }
        return $details === [] ? '  ' . Translator::t('- **%s**', [PlanText::name($card['title'])]) : '  ' . Translator::t('- **%s** (%s)', [PlanText::name($card['title']), implode('; ', $details)]);
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function renderCreateStack(array $plan): ?string {
        $stack = $plan['stack'] ?? null;
        $board = $plan['board'] ?? null;
        if (!is_array($stack) || !is_string($stack['title'] ?? null) || $stack['title'] === '') {
            return null;
        }
        if (!is_array($board) || !is_string($board['board'] ?? null) || $board['board'] === '') {
            return null;
        }
        $lines = [Translator::t(
            'Create list in %s: **%s**',
            [ProtocolPlanText::em($board['board']), PlanText::name($stack['title'])],
        )];
        $lines[] = is_int($stack['order'] ?? null) ? Translator::t('- Position: %d', [$stack['order']]) : Translator::t('- Position: after the last list');
        array_push($lines, ...PlanText::sharedLine($board));
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function renderDeleteBoard(array $plan): ?string {
        $board = $plan['board'] ?? null;
        if (!is_array($board) || !is_string($board['title'] ?? null) || $board['title'] === '') {
            return null;
        }
        $lines = [Translator::t('Delete board: **%s**', [PlanText::name($board['title'])])];
        $lines[] = Translator::t('- The board has no cards.');
        $stacks = array_values(array_filter(
            is_array($plan['stacks'] ?? null) ? $plan['stacks'] : [],
            static fn($title): bool => is_string($title) && $title !== '',
        ));
        $lines[] = $stacks === [] ? Translator::t('- It has no lists.') : Translator::t('- Lists that go with it: %s', [ProtocolPlanText::inline(implode(', ', $stacks), 300)]);
        array_push($lines, ...PlanText::consequenceLine($plan));
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function renderDeleteStack(array $plan): ?string {
        $stack = $plan['stack'] ?? null;
        $board = $plan['board'] ?? null;
        if (!is_array($stack) || !is_string($stack['title'] ?? null) || $stack['title'] === '') {
            return null;
        }
        if (!is_array($board) || !is_string($board['board'] ?? null) || $board['board'] === '') {
            return null;
        }
        $lines = [Translator::t(
            'Delete list in %s: **%s**',
            [ProtocolPlanText::em($board['board']), PlanText::name($stack['title'])],
        )];
        $lines[] = Translator::t('- The list has no cards.');
        array_push($lines, ...PlanText::consequenceLine($plan));
        array_push($lines, ...PlanText::sharedLine($board));
        return implode("\n", $lines);
    }
}
