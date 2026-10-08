<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools;

use OCA\Mcp\L10n\Translator;

/**
 * A call to a tool that does not exist or that the caller may not use.
 *
 * Both look the same on purpose, so a hidden tool is not told apart from a missing one. The message stays
 * the short fixed one clients match on; the details give the argument and a rule like every other -32602,
 * and never the name that was asked for.
 */
final class UnknownToolException extends ArgumentValidationException {
    public function __construct() {
        parent::__construct('Unknown tool', 'name', Translator::t('unknown tool; use one listed by tools/list'));
    }

    /** @return string the fixed message, not the "field: rule" form of the other argument errors */
    public function clientMessage(): string {
        return 'Unknown tool';
    }
}
