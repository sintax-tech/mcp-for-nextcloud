<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Logs\Reading;

use Closure;
use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Logs\Presentation\LogRedactor;

/** Filters incrementally, preserving eligible-entry offsets and reporting time pauses separately from depth exhaustion. */
final class TimedLogScan {
	/** @param LogReader $reader Official current-file source. @param Closure|null $clock Testable monotonic seconds. */
	public function __construct(private LogReader $reader, private ?Closure $clock = null) {}

	/**
	 * @param LogFilter $filter Validated filters.
	 * @param int $offset Eligible entries to skip; oversized lines are explicit unparsed slots.
	 * @param int|null $limit Page size, or null to analyze all matches in the bounded window.
	 * @return array<string,mixed> Entries, exact continuation and scan diagnostics.
	 * @throws InvalidArgumentException For offsets beyond the existing depth budget.
	 * @throws ToolFailure For a read failure, without leaking the path.
	 */
	public function scan(LogFilter $filter, int $offset, ?int $limit): array {
		if ($offset < 0 || $offset > LogScanner::MAX_OFFSET) {
			throw new InvalidArgumentException('offset must be between 0 and ' . LogScanner::MAX_OFFSET
				. ': the log is read from its end and older entries are beyond the read budget of this tool');
		}
		$deadline = new LogDeadline(LogScanner::TIME_BUDGET_MS, $this->clock);
		$window = min(LogScanner::SCAN_LIMIT, LogScanner::READ_BUDGET - $offset);
		if ($limit !== null && $filter->isEmpty()) {
			$window = min($limit, $window);
		}
		$entries = $oversized = [];
		$scanned = $malformed = $unparsed = $skipped = 0;
		$oldest = null;
		$ended = $full = $timedOut = false;
		if ($window > 0) {
			$file = $this->reader->open();
			try {
				$minimum = $this->reader->minimumLevel();
				while ($scanned < $window) {
					$line = $file->next($deadline);
					if ($line === null) {
						$timedOut = $file->timedOut;
						$ended = !$timedOut;
						break;
					}
					$entry = $line['entry'];
					// Match core's comparison, including null >= 0 for malformed lines at level zero.
					if (!$line['oversized'] && (($entry instanceof \stdClass ? ($entry->level ?? null) : null) < $minimum)) {
						continue;
					}
					if ($skipped < $offset) {
						$skipped++;
						continue;
					}
					$position = $offset + $scanned++;
					if ($line['oversized']) {
						$unparsed++;
						$oversized[] = ['offset' => $position, 'bytes' => $line['bytes'], 'reason' => 'oversized'];
						continue;
					}
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
			} catch (\Throwable $error) {
				throw new ToolFailure(Translator::t('The server log could not be read.'), previous: $error);
			} finally {
				$file->close();
			}
		}
		$budget = !$ended && $offset + $scanned >= LogScanner::READ_BUDGET;
		return ['entries' => $entries, 'scanned' => $scanned, 'truncated' => !$full && !$ended,
			'budgetReached' => $budget, 'timeBudgetReached' => $timedOut, 'oversized' => $oversized,
			'oldestScanned' => $oldest === null ? null : LogRedactor::text($oldest, 64),
			'nextOffset' => $ended || $budget ? null : $offset + $scanned, 'malformed' => $malformed, 'unparsed' => $unparsed];
	}
}
