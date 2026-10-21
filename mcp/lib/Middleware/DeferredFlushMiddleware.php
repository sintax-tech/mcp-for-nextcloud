<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Middleware;

use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCP\AppFramework\Middleware;

/**
 * The opportunistic flush of contracts §3.3.6 at the entry of every route of the app: facts captured inside a
 * caller's transaction earlier in this process are reconciled here, if the connection is outside any transaction.
 * Never throws and never changes the request.
 */
class DeferredFlushMiddleware extends Middleware {
    public function __construct(private CauseProcessor $causes) {}

    public function beforeController($controller, $methodName): void {
        $this->causes->flush();
    }
}
