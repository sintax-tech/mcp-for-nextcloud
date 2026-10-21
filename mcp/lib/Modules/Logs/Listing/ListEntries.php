<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Listing;

use OCA\Mcp\Modules\Logs\Reading\LogQuery;
use OCA\Mcp\Modules\Logs\Privacy\LogRedactor;
use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;

/** Lists a bounded page, redacting each native entry before presentation. */
final class ListEntries {
    public function __construct(private LogQuery $query, private LogEnvelope $envelope) {}

    public function list(array $arguments): array {
        $window = $this->query->read($arguments, true);
        return $this->envelope->result('logs_list', $window, [
            'count' => count($window->entries()),
            'entries' => array_map(static fn (\stdClass $entry): array => LogRedactor::entry($entry, $window->messagesHidden), $window->entries()),
        ]);
    }
}
