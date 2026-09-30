<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use InvalidArgumentException;

/** Normalizes a user-relative path and rejects traversal before any storage access. */
final class PathGuard {
    /**
     * @param string $path path relative to the user's root; '' and '/' mean the root
     * @return string '/'-prefixed path without empty or '.' segments
     * @throws InvalidArgumentException for '..', NUL or control characters
     */
    public static function normalize(string $path): string {
        if (str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Invalid argument: path');
        }
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Invalid argument: path');
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }
}
