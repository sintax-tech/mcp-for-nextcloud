<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Analysis;

use OCA\Mcp\Modules\Logs\Reading\LogQuery;
use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;

/** Summarizes one bounded window under its privacy policy. */
final class AnalyzeEntries {
    public function __construct(private LogQuery $query, private LogEnvelope $envelope) {}

    public function analyze(array $arguments): array {
        $window = $this->query->read($arguments, false);
        return $this->envelope->result('logs_analyze', $window, [
            'summary' => (new LogAnalyzer($this->query->time(), $window->messagesHidden))->analyze($window->entries()),
        ]);
    }
}
