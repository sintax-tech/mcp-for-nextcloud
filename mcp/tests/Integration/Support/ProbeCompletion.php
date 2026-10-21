<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Support;

/** A host exception handler may exit successfully; CLI probes must explicitly acknowledge completion. */
final class ProbeCompletion {
    private bool $completed = false;

    public function __construct() {
        register_shutdown_function(function (): void {
            if (!$this->completed) {
                fwrite(STDERR, "Native probe did not complete.\n");
                exit(1);
            }
        });
    }

    public function complete(): void {
        $this->completed = true;
    }
}
