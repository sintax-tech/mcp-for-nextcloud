<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use RuntimeException;

/** The optional spreed app is disabled for the user or its classes are not loaded; never reaches the client message. */
class TalkUnavailableException extends RuntimeException {
    public function __construct() {
        parent::__construct(Messages::talkUnavailable());
    }
}
