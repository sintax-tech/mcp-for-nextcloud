<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Editing;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Execution\ContactPlan;
use OCA\Mcp\Modules\Contacts\Cards\ContactWriter;
final class ContactEditor
{
    public function __construct(
        private ContactAccess $access,
        private ContactRepository $repository,
        private ContactCard $cards,
        private ContactPlan $plans,
        private ContactWriter $writer,
    )
    {
    }
    private function prepare(array $arguments, string $userId): array
    {
        if (array_intersect(
            array_keys($arguments),
            [
                'name',
                'organization',
                'title',
                'note',
                'url',
                'emails',
                'phones',
            ]
        ) === []) {
            throw new InvalidArgumentException(Translator::t('Provide at least one field to change.'));
        }
        if ($this->access->isSystemPath($userId, $arguments['addressbook'])) {
            throw new ToolFailure(Translator::t('Account contacts are read-only; ask the administrator.'));
        }
        $book = $this->access->resolve($userId, $arguments['addressbook'], true);
        $row = null;
        $before = null;
        $row = $this->repository->row($book->id, $arguments['uri']);
        if (isset($arguments['etag']) && trim($arguments['etag'], '"') !== trim($row['etag'], '"')) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        $old = $this->cards->parse($row['data']);
        $before = $this->cards->item($old);
        $before['vcard'] = $row['data'];
        $card = $this->cards->patch($old, $arguments);
        $uri = $row['uri'] ?? (string) $card->UID . '.vcf';
        $plan = $this->plans->build(
            'contacts_edit_contact',
            $book,
            $row,
            $before,
            $card,
            $userId
        );
        return compact(
            'book',
            'row',
            'card',
            'uri',
            'plan'
        );
    }
    public function preview(array $arguments, string $userId): array
    {
        return $this->prepare($arguments, $userId)['plan'];
    }
    public function execute(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId);
        $this->plans->assertAcknowledged($prepared['plan'], $arguments);
        return $this->writer->save($prepared, $userId);
    }
}
