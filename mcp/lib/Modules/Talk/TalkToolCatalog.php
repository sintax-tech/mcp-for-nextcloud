<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk;

use OCA\Mcp\Mcp\Tools\Tool;

final class TalkToolCatalog
{
    /** @return list<class-string<Tool>> */
    public static function classes(): array
    {
        return [
            Tools\ListConversations::class,
            Tools\ReadMessages::class,
            Tools\Reply::class,
            Tools\AttachFile::class,
            Tools\QuoteFile::class,
            Tools\MessageUser::class,
            Tools\SendBatch::class,
            Tools\CreateGroup::class,
        ];
    }
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::classes() as $class) {
            $definition = $class::definition();
            $definitions[$definition->name] = $definition->toArray();
        }
        return $definitions;
    }
}
