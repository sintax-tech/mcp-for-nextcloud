<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Reading;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Directory\AccountDirectory as SystemContacts;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
final class ContactReader
{
    public function __construct(
        private ContactAccess $access,
        private SystemContacts $system,
        private ContactRepository $repository,
        private ContactCard $cards,
    )
    {
    }
    public function execute(array $arguments, string $userId): array
    {
        if ($this->access->isSystemPath($userId, $arguments['addressbook'])) {
            $account = $this->system->find($userId, $arguments['uri']) ?? throw new ToolFailure(FileAccessMessages::notFound());
            return ToolResult::json($account);
        }
        {
            $book = $this->access->resolve($userId, $arguments['addressbook']);
            $row = $this->repository->row($book->id, $arguments['uri']);
            return ToolResult::json(
                $this->cards->item($this->cards->parse($row['data'])) + [
                    'uri' => $row['uri'],
                    'etag' => $row['etag'],
                    'addressbook' => $book->path,
                ]
            );
        }
    }
}
