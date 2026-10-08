<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ArgumentValidationException;

/** Normalizes a user-relative path and rejects traversal before any storage access. */
final class PathGuard {
    /**
     * @param string $path path relative to the user's root; '' and '/' mean the root
     * @param string $field name of the argument the path came from, a fixed string of the tool and never client data
     * @return string '/'-prefixed path without empty or '.' segments
     * @throws ArgumentValidationException for '..', NUL or control characters, with the argument and a fixed rule and never the path
     */
    public static function normalize(string $path, string $field = 'path'): string {
        if (str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw self::invalid($field);
        }
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw self::invalid($field);
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    /**
     * @param string $field argument the path came from
     * @return ArgumentValidationException carrying the argument and the fixed rule, nothing of the path
     */
    private static function invalid(string $field): ArgumentValidationException {
        return new ArgumentValidationException('Invalid argument: ' . $field, $field, Translator::t('must stay inside your folder: no parent (..) segments or control characters'));
    }
}
