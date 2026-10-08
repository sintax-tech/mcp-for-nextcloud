<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Logs;

/**
 * Reads one window of the log from the end and applies the filter to it.
 *
 * A call never reads more than SCAN_LIMIT entries: the core reads the file backwards one character at a time, so
 * the cap is what keeps a call short on a large log. Without a filter only the page itself is read. The answer says
 * how far the window went, so the caller goes on exactly where it stopped:
 * - scanned: entries read in this call, malformed lines included;
 * - truncated: the cap ended the search before the page was full and before the file ended;
 * - oldestScanned: the time of the oldest entry read;
 * - nextOffset: the offset of the next call, null when nothing older can match.
 */
class LogScanner {
    /** Entries read from the end of the log in one call, at most. */
    public const SCAN_LIMIT = 5000;

    public function __construct(private LogReader $reader) {}

    /**
     * @param LogFilter $filter filters of the call
     * @param int $offset entries to skip from the end, the nextOffset of a previous call
     * @param int|null $limit matching entries wanted; null collects every match of the window
     * @return array{entries: list<\stdClass>, scanned: int, truncated: bool, oldestScanned: string|null, nextOffset: int|null, malformed: int, unparsed: int}
     */
    public function scan(LogFilter $filter, int $offset, ?int $limit): array {
        $fetch = $limit !== null && $filter->isEmpty() ? min($limit, self::SCAN_LIMIT) : self::SCAN_LIMIT;
        $raw = $this->reader->entries($fetch, $offset);
        // Fewer entries than asked means the file ended inside this window.
        $fileEnded = count($raw) < $fetch;
        $entries = [];
        $scanned = $malformed = $unparsed = 0;
        $oldest = null;
        $beforeWindow = $full = false;
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
        $readAll = $scanned === count($raw);
        $nextOffset = match (true) {
            $beforeWindow => null,
            $fileEnded && $readAll => null,
            default => $offset + $scanned,
        };
        return [
            'entries' => $entries,
            'scanned' => $scanned,
            'truncated' => !$beforeWindow && !$full && !$fileEnded,
            'oldestScanned' => $oldest === null ? null : LogRedactor::text($oldest, 64),
            'nextOffset' => $nextOffset,
            'malformed' => $malformed,
            'unparsed' => $unparsed,
        ];
    }
}
