<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

final class PlanFields
{
    /**
     * The conversation the plan writes into, by the name the user knows it by.
     *
     * @param array<string, mixed> $plan Plan of a tool that lands in an existing conversation
     * @return string|null The display name, or null when the plan does not carry one
     */
    public static function conversationName(array $plan): ?string
    {
        return self::stringAt($plan, 'conversation', 'displayName');
    }

    /**
     * Reads a value nested under keys, without assuming any of them is there.
     *
     * @param array<string, mixed> $plan Plan, or fragment, to read from
     * @param string ...$keys Keys to walk, in order
     * @return mixed The value found, or null when any key of the path is missing
     */
    public static function valueAt(array $plan, string ...$keys): mixed
    {
        $value = $plan;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Reads a non-empty text nested under keys.
     *
     * @param array<string, mixed> $plan Plan, or fragment, to read from
     * @param string ...$keys Keys to walk, in order
     * @return string|null The text, or null when the path is missing or the value is not a usable text
     */
    public static function stringAt(array $plan, string ...$keys): ?string
    {
        $value = self::valueAt($plan, ...$keys);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
