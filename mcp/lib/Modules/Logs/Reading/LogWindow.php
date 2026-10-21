<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Reading;

/** Internal scan state; only payload() and explicitly redacted entries reach MCP. */
final readonly class LogWindow {
    /** @param array{entries:list<\stdClass>,scanned:int,truncated:bool,budgetReached:bool,oldestScanned:?string,nextOffset:?int,malformed:int,unparsed:int} $scan */
    public function __construct(private LogFilter $filter, private array $scan, public bool $messagesHidden) {}

    /** @return list<\stdClass> native entries for feature-local projection only */
    public function entries(): array { return $this->scan['entries']; }

    /** @return array<string,mixed> public filters and scan metadata, without raw entries */
    public function payload(): array {
        return [
            'filters' => $this->filter->describe(),
            'scan' => [
                'scanned' => $this->scan['scanned'],
                'scanLimit' => LogScanner::SCAN_LIMIT,
                'readBudget' => LogScanner::READ_BUDGET,
                'truncated' => $this->scan['truncated'],
                'budgetReached' => $this->scan['budgetReached'],
                'oldestScanned' => $this->scan['oldestScanned'],
                'nextOffset' => $this->scan['nextOffset'],
                'malformed' => $this->scan['malformed'],
                'unparsed' => $this->scan['unparsed'],
            ],
        ];
    }
}
