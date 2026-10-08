<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

use InvalidArgumentException;

/**
 * Reads one window of the log from the end, in a single getEntries() call, and applies the filter to it.
 *
 * The core's IFileBased::getEntries() reopens the file and walks it backwards from its end one character at a time,
 * through every line, those below the `loglevel` setting included, and offers no budget of its own. So each answer
 * makes exactly one call: a second one would pay the walk again and, with the log growing in between, return the
 * same entry twice. The budget is counted in entries getEntries() returns, those at or above the log level:
 * - nothing deeper than READ_BUDGET such entries from the end is ever read: an offset is at most that much, a call
 *   reads at most SCAN_LIMIT entries after it, and the window shrinks so that offset + window never passes it;
 * - without a filter only the page itself is read.
 *
 * The order of the file is never trusted to end a search: an entry before `since` is skipped, and only the end of the
 * window or of the file ends it. The answer says how far the window went:
 * - scanned: entries read in this call, malformed lines included;
 * - truncated: the window ended before the page was full and before the file ended;
 * - budgetReached: the window reached READ_BUDGET, so there is no further offset to give;
 * - oldestScanned: the time of the oldest entry read;
 * - nextOffset: the offset of the next call, null when the file ended or the budget is spent.
 */
class LogScanner {
    /** Entries read from the end of the log in one call, at most. */
    public const SCAN_LIMIT = 5000;
    /** Deepest entry, counted from the end among those at or above the log level, any call may read. */
    public const READ_BUDGET = 20000;
    /** Largest offset a call may start from; at the budget itself nothing is left to read. */
    public const MAX_OFFSET = self::READ_BUDGET;

    public function __construct(private LogReader $reader) {}

    /**
     * @param LogFilter $filter filters of the call
     * @param int $offset entries to skip from the end, the nextOffset of a previous call
     * @param int|null $limit matching entries wanted; null collects every match of the window
     * @return array{entries: list<\stdClass>, scanned: int, truncated: bool, budgetReached: bool, oldestScanned: string|null, nextOffset: int|null, malformed: int, unparsed: int}
     * @throws InvalidArgumentException for an offset outside 0..MAX_OFFSET, before anything is read
     */
    public function scan(LogFilter $filter, int $offset, ?int $limit): array {
        if ($offset < 0 || $offset > self::MAX_OFFSET) {
            throw new InvalidArgumentException('offset must be between 0 and ' . self::MAX_OFFSET
                . ': the log is read from its end and older entries are beyond the read budget of this tool');
        }
        $window = min(self::SCAN_LIMIT, self::READ_BUDGET - $offset);
        if ($limit !== null && $filter->isEmpty()) {
            $window = min($limit, $window);
        }
        $raw = $window > 0 ? $this->reader->entries($window, $offset) : [];
        // Fewer entries than asked means the file ended inside this window.
        $fileEnded = count($raw) < $window;
        $entries = [];
        $scanned = $malformed = $unparsed = 0;
        $oldest = null;
        $full = false;
        foreach ($raw as $entry) {
            $scanned++;
            if (!$entry instanceof \stdClass || !is_numeric($entry->level ?? null)) {
                $malformed++;
                continue;
            }
            if (is_string($entry->time ?? null)) {
                $oldest = $entry->time;
            }
            $verdict = $filter->test($entry);
            if ($verdict === LogFilter::UNPARSED) {
                $unparsed++;
            } elseif ($verdict === LogFilter::MATCH) {
                $entries[] = $entry;
                if ($limit !== null && count($entries) >= $limit) {
                    $full = true;
                    break;
                }
            }
        }
        // A full page stops inside the window: what is left of it is still to read.
        $fileEnded = $fileEnded && !($full && $scanned < count($raw));
        $budgetReached = !$fileEnded && $offset + $scanned >= self::READ_BUDGET;
        return [
            'entries' => $entries,
            'scanned' => $scanned,
            'truncated' => !$full && !$fileEnded,
            'budgetReached' => $budgetReached,
            'oldestScanned' => $oldest === null ? null : LogRedactor::text($oldest, 64),
            'nextOffset' => $fileEnded || $budgetReached ? null : $offset + $scanned,
            'malformed' => $malformed,
            'unparsed' => $unparsed,
        ];
    }
}
