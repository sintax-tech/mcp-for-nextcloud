<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Reading;

use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;
use OCA\Mcp\Modules\Logs\Presentation\LogRedactor;

/** Backs `logs_list`: one page of redacted entries, newest first, inside the untrusted envelope. */
final class ListLogsHandler {
	/**
	 * @param LogsReadWindow $window the shared read of the current log file
	 */
	public function __construct(
		private LogsReadWindow $window,
	) {
	}

	/**
	 * Lists one page.
	 *
	 * @param array<string, mixed> $arguments arguments validated against the schema, defaults applied by the window
	 * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>} the page
	 * @throws \InvalidArgumentException for an invalid filter, limit or offset, and for contains while messages are hidden
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the log cannot be read
	 * @throws \JsonException when the answer cannot be encoded
	 */
	public function handle(array $arguments): array {
		$read = $this->window->read($arguments, true);
		$payload = $read['payload'];
		$payload['count'] = count($read['window']['entries']);
		$payload['entries'] = array_map(static fn (\stdClass $entry): array => LogRedactor::entry($entry, $read['hide']), $read['window']['entries']);
		return LogEnvelope::result('logs_list', $this->window->source($read['hide']), $payload);
	}
}
