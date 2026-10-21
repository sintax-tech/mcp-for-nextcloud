<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText as ProtocolPlanText;

class CardPlanRenderer {
    /**
     * @param array<string, mixed> $plan
     */
    public static function renderCreate(array $plan, \DateTimeZone $zone): ?string {
        $card = $plan['card'] ?? null;
        $dest = $plan['destination'] ?? null;
        if (!is_array($card) || !isset($card['title']) || !is_string($card['title']) || $card['title'] === '') {
            return null;
        }
        if (!is_array($dest) || !isset($dest['board']) || !is_string($dest['board']) || $dest['board'] === '') {
            return null;
        }
        $title = PlanText::name($card['title']);
        $place = PlanText::place($dest['board'], $dest['list'] ?? null);
        $lines = [];
        $lines[] = Translator::t('Create card in %s: **%s**', [$place, $title]);
        if (isset($card['description']) && is_string($card['description']) && $card['description'] !== '') {
            $lines[] = Translator::t('- Description: %s', [PlanText::truncate($card['description'])]);
        }
        $dueDate = $card['dueDate'] ?? $card['duedate'] ?? null;
        if (is_string($dueDate) && $dueDate !== '') {
            $lines[] = Translator::t('- Due date: %s', [PlanText::formatDate($dueDate, $zone)]);
        }
        $assignees = PlanText::extractAssignees($card);
        if ($assignees !== []) {
            $lines[] = count($assignees) === 1 ? Translator::t('- Assignee: %s', [ProtocolPlanText::inline($assignees[0])]) : Translator::t('- Assignees: %s', [ProtocolPlanText::inline(implode(', ', $assignees), 300)]);
        }
        if (!empty($dest['shared'])) {
            $owner = !empty($dest['ownerDisplayName']) && is_string($dest['ownerDisplayName']) ? $dest['ownerDisplayName'] : (string) ($dest['owner'] ?? '');
            $owner = ProtocolPlanText::inline($owner);
            if ($owner !== '') {
                $lines[] = Translator::t('- Shared board of %s.', [$owner]);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function renderEdit(array $plan, \DateTimeZone $zone): ?string {
        $card = $plan['card'] ?? null;
        if (!is_array($card) || !isset($card['before'], $card['after']) || !is_array($card['before']) || !is_array($card['after'])) {
            return null;
        }
        $changed = $plan['changed'] ?? null;
        if (!is_array($changed)) {
            return null;
        }
        $before = $card['before'];
        $after = $card['after'];
        $title = $before['title'] ?? $after['title'] ?? null;
        if (!is_string($title) || $title === '') {
            return null;
        }
        $title = PlanText::name($title);
        $board = $plan['board'] ?? null;
        $boardName = is_array($board) && isset($board['board']) && is_string($board['board']) ? ProtocolPlanText::em($board['board']) : '';
        $lines = [];
        if ($boardName !== '') {
            $lines[] = Translator::t('Edit card in %s: **%s**', [$boardName, $title]);
        } else {
            $lines[] = Translator::t('Edit card: **%s**', [$title]);
        }
        foreach ($changed as $field) {
            if ($field === 'title' && isset($before['title'], $after['title'])) {
                $lines[] = Translator::t(
                    '- Title: %s → %s',
                    [PlanText::name((string) $before['title']), PlanText::name((string) $after['title'])],
                );
            } elseif ($field === 'description' && (array_key_exists('description', $before) || array_key_exists('description', $after))) {
                $beforeDesc = !empty($before['description']) ? PlanText::truncate((string) $before['description']) : Translator::t('(empty)');
                $afterDesc = !empty($after['description']) ? PlanText::truncate((string) $after['description']) : Translator::t('(empty)');
                $lines[] = Translator::t('- Description: %s → %s', [$beforeDesc, $afterDesc]);
            } elseif ($field === 'duedate' && (array_key_exists('duedate', $before) || array_key_exists('duedate', $after))) {
                $beforeDue = !empty($before['duedate']) ? PlanText::formatDate((string) $before['duedate'], $zone) : Translator::t('(no due date)');
                $afterDue = !empty($after['duedate']) ? PlanText::formatDate((string) $after['duedate'], $zone) : Translator::t('(no due date)');
                $lines[] = Translator::t('- Due date: %s → %s', [$beforeDue, $afterDue]);
            }
        }
        array_push($lines, ...self::assigneeLines($plan['assignees'] ?? null));
        if (is_array($board) && !empty($board['shared'])) {
            $owner = !empty($board['ownerDisplayName']) && is_string($board['ownerDisplayName']) ? $board['ownerDisplayName'] : (string) ($board['owner'] ?? '');
            $owner = ProtocolPlanText::inline($owner);
            if ($owner !== '') {
                $lines[] = Translator::t('- Shared board of %s.', [$owner]);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Lines of the assignee section of an edit plan: who joins, who leaves, the final list and what is ignored.
     *
     * @param mixed $assignees `assignees` section of the plan, or null when the call did not touch them.
     * @return list<string> Markdown lines, empty when there is nothing to say.
     */
    public static function assigneeLines(mixed $assignees): array {
        if (!is_array($assignees)) {
            return [];
        }
        $lines = [];
        $added = PlanText::extractAssignees(['assignees' => $assignees['added'] ?? []]);
        $removed = PlanText::extractAssignees(['assignees' => $assignees['removed'] ?? []]);
        if ($added !== []) {
            $lines[] = Translator::t('- Assigned: %s', [ProtocolPlanText::inline(implode(', ', $added), 300)]);
        }
        if ($removed !== []) {
            $lines[] = Translator::t('- Unassigned: %s', [ProtocolPlanText::inline(implode(', ', $removed), 300)]);
        }
        if (array_key_exists('after', $assignees)) {
            $after = PlanText::extractAssignees(['assignees' => $assignees['after']]);
            $lines[] = Translator::t(
                '- Assignees after the change: %s',
                [$after === [] ? Translator::t('(nobody)') : ProtocolPlanText::inline(implode(', ', $after), 300)],
            );
        }
        $notAssigned = self::uids($assignees['notAssigned'] ?? []);
        if ($notAssigned !== []) {
            $lines[] = Translator::t(
                '- Ignored, not assigned to the card: %s',
                [ProtocolPlanText::inline(implode(', ', $notAssigned), 300)],
            );
        }
        $already = self::uids($assignees['alreadyAssigned'] ?? []);
        if ($already !== []) {
            $lines[] = Translator::t(
                '- Ignored, already assigned to the card: %s',
                [ProtocolPlanText::inline(implode(', ', $already), 300)],
            );
        }
        return $lines;
    }

    /**
     * @param mixed $value Account IDs of the plan.
     * @return list<string> The non-empty strings of the list.
     */
    public static function uids(mixed $value): array {
        return is_array($value) ? array_values(array_filter($value, static fn($uid): bool => is_string($uid) && $uid !== '')) : [];
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function renderMove(array $plan): ?string {
        $card = $plan['card'] ?? null;
        $orig = $plan['origin'] ?? null;
        $dest = $plan['destination'] ?? null;
        if (!is_array($card) || !isset($card['title']) || !is_string($card['title']) || $card['title'] === '') {
            return null;
        }
        if (!is_array($orig) || !isset($orig['board']) || !is_string($orig['board']) || $orig['board'] === '') {
            return null;
        }
        if (!is_array($dest) || !isset($dest['board']) || !is_string($dest['board']) || $dest['board'] === '') {
            return null;
        }
        $title = PlanText::name($card['title']);
        $lines = [];
        $lines[] = Translator::t('Move card: **%s**', [$title]);
        $origPlace = PlanText::place($orig['board'], $orig['list'] ?? null);
        $destPlace = PlanText::place($dest['board'], $dest['list'] ?? null);
        if (!empty($plan['sameList'])) {
            $lines[] = Translator::t('- Reorder in list: %s', [$origPlace]);
        } else {
            $lines[] = Translator::t('- From: %s', [$origPlace]);
            $lines[] = Translator::t('- To: %s', [$destPlace]);
        }
        if (isset($plan['order']) && is_int($plan['order'])) {
            $lines[] = Translator::t('- Position: %d', [$plan['order']]);
        }
        if (!empty($dest['shared'])) {
            $owner = !empty($dest['ownerDisplayName']) && is_string($dest['ownerDisplayName']) ? $dest['ownerDisplayName'] : (string) ($dest['owner'] ?? '');
            $owner = ProtocolPlanText::inline($owner);
            if ($owner !== '') {
                $lines[] = Translator::t('- Destination board shared by %s.', [$owner]);
            }
        } elseif (!empty($orig['shared'])) {
            $owner = !empty($orig['ownerDisplayName']) && is_string($orig['ownerDisplayName']) ? $orig['ownerDisplayName'] : (string) ($orig['owner'] ?? '');
            $owner = ProtocolPlanText::inline($owner);
            if ($owner !== '') {
                $lines[] = Translator::t('- Origin board shared by %s.', [$owner]);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function renderDelete(array $plan): ?string {
        $card = $plan['card'] ?? null;
        if (!is_array($card) || !isset($card['title']) || !is_string($card['title']) || $card['title'] === '') {
            return null;
        }
        $title = PlanText::name($card['title']);
        $board = $plan['board'] ?? null;
        $boardName = is_array($board) && isset($board['board']) && is_string($board['board']) ? ProtocolPlanText::em($board['board']) : '';
        $lines = [];
        if ($boardName !== '') {
            $lines[] = Translator::t('Delete card in %s: **%s**', [$boardName, $title]);
        } else {
            $lines[] = Translator::t('Delete card: **%s**', [$title]);
        }
        if (isset($plan['consequence']) && is_string($plan['consequence']) && $plan['consequence'] !== '') {
            $lines[] = '- ' . ProtocolPlanText::inline($plan['consequence'], 300);
        }
        if (is_array($board) && !empty($board['shared'])) {
            $owner = !empty($board['ownerDisplayName']) && is_string($board['ownerDisplayName']) ? $board['ownerDisplayName'] : (string) ($board['owner'] ?? '');
            $owner = ProtocolPlanText::inline($owner);
            if ($owner !== '') {
                $lines[] = Translator::t('- Shared board of %s.', [$owner]);
            }
        }
        return implode("\n", $lines);
    }
}
