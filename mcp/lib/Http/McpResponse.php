<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Http;

use OCP\AppFramework\Http\Response;

/** JSON response with a pre-encoded body and no caching, used for every MCP endpoint reply. */
class McpResponse extends Response {
    /**
     * @param string $body already encoded JSON, or '' for no body
     * @param int $status HTTP status code
     */
    public function __construct(private string $body = '', int $status = 200) {
        parent::__construct();
        $this->setStatus($status);
        $this->addHeader('Content-Type', 'application/json; charset=utf-8');
        $this->addHeader('Cache-Control', 'no-store');
    }

    /** @return string the body exactly as given */
    public function render(): string {
        return $this->body;
    }
}
