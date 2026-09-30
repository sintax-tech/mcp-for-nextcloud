<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

/**
 * Unified diff between the previous and the new content of a text file, with no external dependency.
 *
 * The common prefix and suffix are stripped first, which is what makes a localized change inside a large
 * file cheap; the remaining middles are compared with a longest-common-subsequence table bounded by
 * BUDGET_CELLS. Above that bound, or above MAX_LINES on either side, no table is built at all and a
 * summary is returned instead — a 10 MiB file must not be able to exhaust memory.
 */
final class UnifiedDiff {
    /** Characters kept in the rendered diff before it is cut. */
    public const MAX_CHARS = 20000;
    /** Lines kept per side before the diff degrades to a summary. */
    public const MAX_LINES = 20000;
    /** Cells the longest-common-subsequence table may occupy. */
    private const BUDGET_CELLS = 4000000;
    /** Context lines kept around each hunk. */
    private const CONTEXT = 3;
    /** Appended to a diff cut by MAX_CHARS. */
    public const TRUNCATED = '[diff truncado]';

    /**
     * @param string $before content as read
     * @param string $after content to be written
     * @return string the unified diff, or '' when the contents are identical
     */
    public static function between(string $before, string $after): string {
        $old = self::lines($before);
        $new = self::lines($after);
        // Line endings are normalized first, so a pure CRLF change is not a change at all.
        if ($old === $new) {
            return '';
        }
        if (count($old) > self::MAX_LINES || count($new) > self::MAX_LINES) {
            return self::summary(count($old), count($new));
        }
        $diff = self::render($old, $new);
        return mb_strlen($diff) > self::MAX_CHARS ? mb_substr($diff, 0, self::MAX_CHARS) . "\n" . self::TRUNCATED : $diff;
    }

    /**
     * Builds the whole edit script — the equal prefix and suffix as context, only the differing middle
     * through the LCS table — and lets the hunker cut it into windows. Line numbers then fall out of the
     * op index: an op sits at 1 + index minus what earlier ops removed (old) or added (new).
     *
     * @param list<string> $old previous lines
     * @param list<string> $new new lines
     * @return string the unified diff of both sides
     */
    private static function render(array $old, array $new): string {
        $head = 0;
        $limit = min(count($old), count($new));
        while ($head < $limit && $old[$head] === $new[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < $limit - $head && $old[count($old) - 1 - $tail] === $new[count($new) - 1 - $tail]) {
            $tail++;
        }
        $oldMiddle = array_slice($old, $head, count($old) - $head - $tail);
        $newMiddle = array_slice($new, $head, count($new) - $head - $tail);
        if (count($oldMiddle) * count($newMiddle) > self::BUDGET_CELLS) {
            return self::summary(count($old), count($new));
        }
        $context = static fn (int $from, int $to): array => $from >= $to ? [] : array_map(static fn (int $i) => [' ', $old[$i]], range($from, $to - 1));
        $ops = array_merge(
            $context(0, $head),
            self::ops($oldMiddle, $newMiddle),
            $context(count($old) - $tail, count($old)),
        );
        $hunks = self::hunks($ops);
        return $hunks === [] ? '' : "--- antes\n+++ depois\n" . implode('', $hunks);
    }

    /**
     * Groups the changed lines into unified hunks with CONTEXT lines around each one.
     *
     * @param list<array{0:string, 1:string}> $ops edit script over the whole file
     * @return list<string> rendered hunks, each ending with a newline
     */
    private static function hunks(array $ops): array {
        $changed = [];
        foreach ($ops as $index => $op) {
            if ($op[0] !== ' ') {
                $changed[] = $index;
            }
        }
        if ($changed === []) {
            return [];
        }
        $groups = [];
        $start = $changed[0];
        $previous = $changed[0];
        foreach (array_slice($changed, 1) as $index) {
            if ($index - $previous > self::CONTEXT * 2) {
                $groups[] = [$start, $previous];
                $start = $index;
            }
            $previous = $index;
        }
        $groups[] = [$start, $previous];

        $hunks = [];
        foreach ($groups as [$first, $last]) {
            $from = max(0, $first - self::CONTEXT);
            $to = min(count($ops) - 1, $last + self::CONTEXT);
            $oldStart = $newStart = $oldCount = $newCount = 0;
            $body = '';
            for ($i = $from; $i <= $to; $i++) {
                [$mark, $text] = $ops[$i];
                $body .= $mark . $text . "\n";
                // A line survives in the old file unless an earlier op added one, and in the new file
                // unless an earlier op removed one.
                if ($mark !== '+') {
                    $oldStart = $oldStart === 0 ? 1 + $i - self::addedBefore($ops, $i) : $oldStart;
                    $oldCount++;
                }
                if ($mark !== '-') {
                    $newStart = $newStart === 0 ? 1 + $i - self::removedBefore($ops, $i) : $newStart;
                    $newCount++;
                }
            }
            $hunks[] = sprintf("@@ -%d,%d +%d,%d @@\n", $oldStart, $oldCount, $newStart, $newCount) . $body;
        }
        return $hunks;
    }

    /**
     * @param list<array{0:string, 1:string}> $ops edit script
     * @param int $index op index
     * @return int how many removed lines come before it
     */
    private static function removedBefore(array $ops, int $index): int {
        $removed = 0;
        for ($i = 0; $i < $index; $i++) {
            $removed += $ops[$i][0] === '-' ? 1 : 0;
        }
        return $removed;
    }

    /**
     * @param list<array{0:string, 1:string}> $ops edit script
     * @param int $index op index
     * @return int how many added lines come before it
     */
    private static function addedBefore(array $ops, int $index): int {
        $added = 0;
        for ($i = 0; $i < $index; $i++) {
            $added += $ops[$i][0] === '+' ? 1 : 0;
        }
        return $added;
    }

    /**
     * Longest-common-subsequence edit script over the trimmed middles.
     *
     * @param list<string> $old previous lines
     * @param list<string> $new new lines
     * @return list<array{0:string, 1:string}> per-line marks (' ', '-', '+') with the line text
     */
    private static function ops(array $old, array $new): array {
        $rows = count($old);
        $columns = count($new);
        $table = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));
        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $columns - 1; $j >= 0; $j--) {
                $table[$i][$j] = $old[$i] === $new[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $rows && $j < $columns) {
            if ($old[$i] === $new[$j]) {
                $ops[] = [' ', $old[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $ops[] = ['-', $old[$i]];
                $i++;
            } else {
                $ops[] = ['+', $new[$j]];
                $j++;
            }
        }
        while ($i < $rows) {
            $ops[] = ['-', $old[$i++]];
        }
        while ($j < $columns) {
            $ops[] = ['+', $new[$j++]];
        }
        return $ops;
    }

    /**
     * @param int $oldLines lines of the previous content
     * @param int $newLines lines of the new content
     * @return string the fallback shown when no diff table can be built
     */
    private static function summary(int $oldLines, int $newLines): string {
        return sprintf("--- antes (%d linhas)\n+++ depois (%d linhas)\n%s", $oldLines, $newLines, self::TRUNCATED);
    }

    /**
     * @param string $content text to split
     * @return list<string> lines without their terminator
     */
    private static function lines(string $content): array {
        if ($content === '') {
            return [];
        }
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
    }
}
