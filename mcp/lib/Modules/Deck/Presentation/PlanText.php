<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText as ProtocolPlanText;

class PlanText {
    private const MAX_TEXT_LENGTH = 200;
    /**
     * Resolves the timezone from the plan if present, falling back to constructor or system default.
     *
     * @param array<string, mixed> $plan
     */
    public static function resolveZone(array $plan, ?\DateTimeZone $fallback = null): \DateTimeZone {
        if (isset($plan['timezone']) && is_string($plan['timezone']) && $plan['timezone'] !== '') {
            try {
                return new \DateTimeZone($plan['timezone']);
            } catch (\Throwable) {
                // fall through
            }
        }
        if ($fallback !== null) {
            return $fallback;
        }
        return new \DateTimeZone(date_default_timezone_get());
    }

    /**
     * @param array<string, mixed> $plan Plan that may carry a `consequence`.
     * @return list<string> The consequence as a bullet, or nothing.
     */
    public static function consequenceLine(array $plan): array {
        return isset($plan['consequence']) && is_string($plan['consequence']) && $plan['consequence'] !== '' ? ['- ' . ProtocolPlanText::inline($plan['consequence'], 300)] : [];
    }

    /**
     * @param array<string, mixed> $board Board of the plan, with its owner when it is somebody else's.
     * @return list<string> The shared-board notice, or nothing.
     */
    public static function sharedLine(array $board): array {
        if (empty($board['shared'])) {
            return [];
        }
        $owner = !empty($board['ownerDisplayName']) && is_string($board['ownerDisplayName']) ? $board['ownerDisplayName'] : (string) ($board['owner'] ?? '');
        $owner = ProtocolPlanText::inline($owner);
        return $owner === '' ? [] : [Translator::t('- Shared board of %s.', [$owner])];
    }

    /**
     * @param array<string, mixed> $card
     * @return list<string>
     */
    public static function extractAssignees(array $card): array {
        $users = $card['assignedUsers'] ?? $card['assignees'] ?? null;
        if (!is_array($users)) {
            return [];
        }
        $names = [];
        foreach ($users as $user) {
            if (is_string($user) && $user !== '') {
                $names[] = $user;
            } elseif (is_array($user)) {
                $name = $user['displayName'] ?? $user['name'] ?? $user['uid'] ?? null;
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }
        return $names;
    }

    public static function formatDate(string $raw, \DateTimeZone $zone): string {
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return $raw;
            }
            $dt = (new \DateTimeImmutable($raw))->setTimezone($zone);
            if ($dt->format('H:i:s') === '00:00:00') {
                return $dt->format('Y-m-d');
            }
            return $dt->format('Y-m-d H:i');
        } catch (\Throwable) {
            return ProtocolPlanText::inline($raw);
        }
    }

    public static function truncate(string $text): string {
        return ProtocolPlanText::inline($text, self::MAX_TEXT_LENGTH);
    }

    /** @param string $title card title typed by somebody else @return string inert text, or the placeholder when nothing is left */
    public static function name(string $title): string {
        $inline = ProtocolPlanText::inline($title);
        return $inline !== '' ? $inline : Translator::t('(no title)');
    }

    /**
     * @param string $board board name
     * @param mixed $list list name, when the plan has one
     * @return string "*board* › *list*" with both names inert
     */
    public static function place(string $board, mixed $list): string {
        $place = ProtocolPlanText::em($board);
        $listName = is_string($list) ? ProtocolPlanText::em($list) : '';
        return $listName !== '' ? $place . ' › ' . $listName : $place;
    }
}
