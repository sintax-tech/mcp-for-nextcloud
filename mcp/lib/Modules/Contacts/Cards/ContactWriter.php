<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Cards;

use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
final class ContactWriter
{
    public function __construct(
        private ContactDav $dav,
        private ContactRepository $repository,
        private ContactCard $cards,
    )
    {
    }
    public function save(array $prepared, string $userId): array
    {
        $book = $prepared['book'];
        $row = $prepared['row'];
        $card = $prepared['card'];
        $result = $row === null ? $this->dav->put(
            $userId,
            $book->uri,
            $prepared['uri'],
            $card->serialize()
        ) : $this->dav->update(
            $userId,
            $book->uri,
            $row['uri'],
            $row['etag'],
            $card->serialize()
        );
        // Read back because native plugins may normalize the card and omit the response ETag.
        $written = $this->repository->row($book->id, $prepared['uri']);
        return ToolResult::json(
            $this->cards->item($this->cards->parse($written['data'])) + [
                'uri' => $written['uri'],
                'etag' => $written['etag'],
                'addressbook' => $book->path,
                'status' => $result->status,
            ]
        );
    }
}
