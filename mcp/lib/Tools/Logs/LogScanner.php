<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

use InvalidArgumentException;

/**
 * Reads one window of the log from the end and applies the filter to it.
 *
 * The core's IFileBased::getEntries() walks the file backwards from its end one character at a time and offers no
 * budget of its own: every read costs the walk from the end down to offset + limit. So the budget is set here:
 * - nothing deeper than READ_BUDGET entries from the end is ever read: an offset is at most that much, a call reads
 *   at most SCAN_LIMIT entries after it, and the window shrinks so that offset + window never passes the budget;
 * - without a filter only the page itself is read;
 * - when the search can end early (a page to fill, or `since`), it reads in blocks that double from FIRST_BLOCK, so a
 *   page found near the end costs a small read and a miss costs about twice the window; otherwise one read.
 *
 * The answer says how far the window went, so the caller goes on exactly where it stopped:
 * - scanned: entries read in this call, malformed lines included;
 * - truncated: the window ended the search before the page was full and before the file ended;
 * - budgetReached: the window reached READ_BUDGET, so there is no further offset to give;
 * - oldestScanned: the time of the oldest entry read;
 * - nextOffset: the offset of the next call, null when nothing older can match or the budget is spent.
 */
class LogScanner {
    /** Entries read from the end of the log in one call, at most. */
    public const SCAN_LIMIT = 5000;
    /** Deepest entry, counted from the end, any call may read. */
    public const READ_BUDGET = 20000;
    /** Largest offset a call may start from; at the budget itself nothing is left to read. */
    public const MAX_OFFSET = self::READ_BUDGET;
    /** First block of a search that may end early; each next block doubles. */
    public const FIRST_BLOCK = 500;

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
        $inBlocks = !$filter->isEmpty() && ($limit !== null || $filter->hasSince());
        $entries = [];
        $scanned = $malformed = $unparsed = 0;
        $oldest = null;
        $beforeWindow = $full = $fileEnded = false;
        $block = $inBlocks ? self::FIRST_BLOCK : $window;
        while (!$beforeWindow && !$full && !$fileEnded && $scanned < $window) {
            $size = min($block, $window - $scanned);
            $raw = $this->reader->entries($size, $offset + $scanned);
            // Fewer entries than asked means the file ended inside this block.
            $fileEnded = count($raw) < $size;
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
                if ($verdict === LogFilter::BEFORE_WINDOW) {
                    $beforeWindow = true;
                    break;
                }
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
            // A stop inside the block leaves entries unread: the file has not ended for the next call.
            $fileEnded = $fileEnded && !$beforeWindow && !$full;
            $block *= 2;
        }
        $budgetReached = !$beforeWindow && !$fileEnded && $offset + $scanned >= self::READ_BUDGET;
        $more = !$beforeWindow && !$fileEnded && !$budgetReached;
        return [
            'entries' => $entries,
            'scanned' => $scanned,
            'truncated' => !$beforeWindow && !$full && !$fileEnded,
            'budgetReached' => $budgetReached,
            'oldestScanned' => $oldest === null ? null : LogRedactor::text($oldest, 64),
            'nextOffset' => $more ? $offset + $scanned : null,
            'malformed' => $malformed,
            'unparsed' => $unparsed,
        ];
    }
}
