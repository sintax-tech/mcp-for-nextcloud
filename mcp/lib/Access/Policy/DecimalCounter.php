<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy;

/** Canonical signed-BIGINT counters, without platform-size casts or arithmetic extensions. */
final class DecimalCounter {
    public const MAX = '9223372036854775807';
    public const MAX_EXPECTED = '9223372036854775806';

    /**
     * @param string $value candidate counter, exactly as stored or received
     * @param string $maximum inclusive upper bound, itself canonical
     * @return string $value unchanged, once proven canonical
     * @throws \InvalidArgumentException for leading zeros, signs, spaces, exponents, non-ASCII digits or overflow
     */
    public static function validate(string $value, string $maximum = self::MAX_EXPECTED): string {
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value)
            || strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new \InvalidArgumentException('Invalid decimal counter');
        }
        return $value;
    }

    /**
     * Orders two canonical counters by length, then bytes: no platform integer or float is ever involved.
     *
     * @param string $a canonical counter
     * @param string $b canonical counter
     * @return int negative, zero or positive, as $a is below, equal to or above $b
     * @throws \InvalidArgumentException when either value is not canonical
     */
    public static function compare(string $a, string $b): int {
        self::validate($a, self::MAX);
        self::validate($b, self::MAX);
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    /**
     * The next counter, by decimal carry on the string: the revision a CAS from $value commits.
     *
     * @param string $value canonical counter at most MAX_EXPECTED
     * @return string $value + 1, at most MAX
     * @throws \InvalidArgumentException when $value is not canonical or is already above MAX_EXPECTED
     */
    public static function increment(string $value): string {
        self::validate($value);
        $digits = str_split($value);
        for ($i = count($digits) - 1; $i >= 0; --$i) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string)((int)$digits[$i] + 1);
                return implode('', $digits);
            }
            $digits[$i] = '0';
        }
        return '1' . implode('', $digits);
    }
}
