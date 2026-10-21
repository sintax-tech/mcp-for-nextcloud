<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanText;

final class MessageExcerpt
{
    public const EXCERPT_MAX_LENGTH = 200;
    /**
     * A message body as a Markdown quote, cut when it is longer than {@see self::EXCERPT_MAX_LENGTH}.
     *
     * @param string $message Message as the plan carries it
     * @return string The block quote, one "> " per line, with no line able to leave the block or to be read as Markdown
     */
    public static function excerpt(string $message): string
    {
        $text = trim($message);
        $cut = mb_strlen($text) > self::EXCERPT_MAX_LENGTH;
        if ($cut) {
            $text = mb_substr($text, 0, self::EXCERPT_MAX_LENGTH);
        }

        // Every line of the message, whatever ends it ("\n", "\r\n" or a lone "\r"), stays inside the block quote.
        $quoted = PlanText::quote($text, self::EXCERPT_MAX_LENGTH);

        return $cut ? $quoted . "\n> " . PlanMessages::contentTruncated() : $quoted;
    }
}
