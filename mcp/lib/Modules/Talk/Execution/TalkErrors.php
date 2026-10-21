<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Execution;

use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Attachments\FileAccessException;
use OCA\Mcp\Platform\Logging\ExceptionSummary;
use Throwable;

class TalkErrors
{
    /**
     * A refusal already carries a message written for the user, so only the unexpected cases fall back to a
     * generic one. The exception message of a Talk failure is never shown.
     *
     * @param Throwable $e Failure raised while running the tool
     * @return string The message the client receives
     */
    public function messageFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof TalkUnavailableException => ErrorMessages::talkUnavailable(),
            $e instanceof ConversationAccessException, $e instanceof FileAccessException => $e->getMessage(),
            default => ErrorMessages::unexpected(),
        };
    }
    /**
     * The message that explains the failure, for the server log only: a refusal wraps the real cause, so the
     * cause is the one worth reading. Sanitized like the registry's, with no paths and a length limit.
     *
     * @param Throwable $e the exception being logged
     * @return string sanitized message
     */
    public static function loggable(Throwable $e): string
    {
        return ExceptionSummary::loggable($e->getPrevious() ?? $e);
    }
}
