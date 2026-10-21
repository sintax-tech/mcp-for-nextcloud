<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\AddressBooks;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\Directory\AccountDirectory as SystemContacts;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
final class AddressBookReader
{
    public function __construct(private ContactAccess $access, private SystemContacts $system)
    {
    }
    public function execute(array $arguments, string $userId): array
    {
        $books = array_map(static fn($book) => (array) $book, $this->access->visible($userId));
        $catalog = $this->system->bookName($userId);
        if ($catalog !== null) {
            $books[] = [
                'id' => 0,
                'uri' => ContactAccess::SYSTEM_URI,
                'name' => $catalog,
                'ownerId' => 'system',
                'ownerPrincipal' => 'principals/system/system',
                'writable' => false,
                'path' => ContactAccess::systemPath($userId),
                'system' => true,
                'readOnly' => true,
            ];
        }
        return ToolResult::json($books);
    }
}
