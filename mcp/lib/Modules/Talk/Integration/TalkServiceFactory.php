<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Integration;

use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;

class TalkServiceFactory
{
    public function __construct(
        private string $className,
        private \Closure $resolve,
        private TalkAvailability $availability,
    ) {}

    public function forUser(string $userId): object
    {
        if (!$this->availability->isEnabledFor($userId) || !class_exists($this->className)) {
            throw new TalkUnavailableException();
        }
        $service = ($this->resolve)();
        if (!is_object($service)) {
            throw new TalkUnavailableException();
        }
        return $service;
    }
}
