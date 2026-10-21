<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

/**
 * A group policy refused because the group cannot grant (contracts §3.3.5): it is not served by exactly one backend
 * named 'Database'. Nothing was written. HTTP adapters map this to 422 {"error":"group_source_untrusted"}.
 */
class GroupSourceUntrusted extends \RuntimeException {
    public function __construct() {
        parent::__construct('Group source cannot guarantee group identity', 422);
    }
}
