<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Messaging;

use InvalidArgumentException;

final class MessageText
{
    public const MAX_MESSAGE_LENGTH = 4000;
    /**
     * The message body as Talk will store it. A draft has to show the very text that will be sent, so the
     * preview normalizes with the same rule instead of trimming a second time on its own.
     *
     * @param string $message Message body as received from the client
     * @return string The trimmed body
     * @throws InvalidArgumentException When the message is blank or longer than self::MAX_MESSAGE_LENGTH
     */
    public static function normalizeMessage(string $message): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            throw new InvalidArgumentException(MessageMessages::emptyMessage());
        }
        if (mb_strlen($trimmed) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidArgumentException(MessageMessages::messageTooLong());
        }

        return $trimmed;
    }
}
