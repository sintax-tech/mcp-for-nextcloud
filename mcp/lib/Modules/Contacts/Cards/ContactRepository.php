<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Cards;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
final class ContactRepository
{
    public function __construct(private ContactStore $store)
    {
    }
    public function row(int $bookId, string $uri): array
    {
        // Reject path syntax even for direct callers; no foreign node can be addressed through the port.
        if ($uri === '' || $uri === '.' || $uri === '..' || strpbrk($uri, "/\\\x00") !== false) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        return $this->store->card($bookId, $uri) ?? throw new ToolFailure(FileAccessMessages::notFound());
    }
}
