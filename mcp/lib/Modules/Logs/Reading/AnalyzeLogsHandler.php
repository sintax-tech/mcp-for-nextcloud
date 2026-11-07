<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Reading;

use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;

/**
 * Backs `logs_analyze`: the same window as the list, counted instead of paged.
 *
 * The time configuration is read again after the scan, for the summary, and is not reused from the filter.
 */
final class AnalyzeLogsHandler {
	/**
	 * @param LogsReadWindow $window the shared read of the current log file
	 */
	public function __construct(
		private LogsReadWindow $window,
	) {
	}

	/**
	 * Summarizes one window.
	 *
	 * @param array<string, mixed> $arguments arguments validated against the schema, defaults applied by the window
	 * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>} the summary
	 * @throws \InvalidArgumentException for an invalid filter or offset, and for contains while messages are hidden
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the log cannot be read
	 * @throws \JsonException when the answer cannot be encoded
	 */
	public function handle(array $arguments): array {
		$read = $this->window->read($arguments, false);
		$payload = $read['payload'];
		$payload['summary'] = (new LogAnalyzer($this->window->time(), $read['hide']))->analyze($read['window']['entries']);
		return LogEnvelope::result('logs_analyze', $this->window->source($read['hide']), $payload);
	}
}
