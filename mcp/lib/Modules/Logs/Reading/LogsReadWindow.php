<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Reading;

use InvalidArgumentException;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;
use OCP\IConfig;

/**
 * The one window both logs tools read: the role of hiding messages, the filter, the page and the scan.
 *
 * Time is read from the configuration on each {@see self::time()} call. An analysis asks for it again after the
 * scan, so a change of format between the filter and the summary is seen, as the module always did.
 */
final class LogsReadWindow {
	/** Default page of logs_list. */
	public const DEFAULT_LIMIT = 50;
	/** Largest page of logs_list. */
	public const MAX_LIMIT = 100;
	/** Level the core writes from when loglevel is not set: warning. */
	public const DEFAULT_LOG_LEVEL = 2;

	/**
	 * @param LogScanner $scanner the single read of the current log file
	 * @param IConfig $config log level, date format and timezone
	 * @param LogFileNames $fileNames whether the administrator hides the text of messages
	 */
	public function __construct(
		private LogScanner $scanner,
		private IConfig $config,
		private LogFileNames $fileNames,
	) {
	}

	/**
	 * Reads one window. A search inside hidden text is refused before anything is read.
	 *
	 * @param array<string, mixed> $arguments arguments of the tool, defaults not applied yet
	 * @param bool $paged true for logs_list, which takes a page size; false reads the window for analysis
	 * @return array{hide:bool, window:array<string, mixed>, payload:array<string, mixed>} whether messages are hidden,
	 *         the scan window and the filters and scan the answer reports
	 * @throws InvalidArgumentException when contains is used while messages are hidden, or the limit or the offset is
	 *         out of range, and for an invalid filter
	 */
	public function read(array $arguments, bool $paged): array {
		$hide = $this->fileNames->active();
		// A search inside hidden text would answer, by its count, whether a name is there: it is refused instead.
		if ($hide && isset($arguments['contains'])) {
			throw new InvalidArgumentException('contains is not available while the administrator hides the text of log '
				. 'messages; filter by min_level, app, user, since, until or req_id instead');
		}
		$filter = LogFilter::fromArguments($arguments, $this->time());
		$offset = (int)($arguments['offset'] ?? 0);
		$limit = $paged ? (int)($arguments['limit'] ?? self::DEFAULT_LIMIT) : null;
		if ($limit !== null && ($limit < 1 || $limit > self::MAX_LIMIT)) {
			throw new InvalidArgumentException('Invalid limit');
		}
		if ($offset < 0 || $offset > LogScanner::MAX_OFFSET) {
			throw new InvalidArgumentException('offset must be between 0 and ' . LogScanner::MAX_OFFSET
				. ': the log is read from its end and older entries are beyond the read budget of this tool');
		}
		$window = $this->scanner->scan($filter, $offset, $limit);
		return [
			'hide' => $hide,
			'window' => $window,
			'payload' => [
				'filters' => $filter->describe(),
				'scan' => [
					'scanned' => $window['scanned'],
					'scanLimit' => LogScanner::SCAN_LIMIT,
					'readBudget' => LogScanner::READ_BUDGET,
					'truncated' => $window['truncated'],
					'budgetReached' => $window['budgetReached'],
					'timeBudgetReached' => $window['timeBudgetReached'],
					'timeBudgetMs' => LogScanner::TIME_BUDGET_MS,
					'oversized' => $window['oversized'],
					'oldestScanned' => $window['oldestScanned'],
					'nextOffset' => $window['nextOffset'],
					'malformed' => $window['malformed'],
					'unparsed' => $window['unparsed'],
				],
			],
		];
	}

	/**
	 * How the core writes the time of an entry on this server, read now.
	 *
	 * @return LogTime the format and the timezone of this call
	 */
	public function time(): LogTime {
		$format = $this->config->getSystemValueString('logdateformat', \DateTimeInterface::ATOM);
		return new LogTime($format === '' ? \DateTimeInterface::ATOM : $format, $this->config->getSystemValueString('logtimezone', 'UTC'));
	}

	/**
	 * What the answer says was read. The log level is read here, when the answer is built, not during the scan.
	 *
	 * @param bool $hide whether the text of messages is hidden
	 * @return array<string, mixed> the file, the server log level, whether messages are hidden and, then, the note
	 */
	public function source(bool $hide): array {
		return [
			'file' => LogEnvelope::SOURCE_FILE,
			'serverLogLevel' => $this->config->getSystemValueInt('loglevel', self::DEFAULT_LOG_LEVEL),
			'messagesHidden' => $hide,
		] + ($hide ? ['messages' => LogEnvelope::HIDDEN_NOTE] : []);
	}
}
