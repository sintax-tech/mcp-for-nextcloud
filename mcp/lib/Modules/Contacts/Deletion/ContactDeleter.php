<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Deletion;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Deletion\ContactBackup;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\Cards\ContactRepository;
use OCA\Mcp\Modules\Contacts\Execution\ContactPlan;
final class ContactDeleter
{
    public function __construct(
        private ContactAccess $access,
        private ContactRepository $repository,
        private ContactCard $cards,
        private ContactPlan $plans,
        private ContactBackup $backup,
        private ContactDav $dav,
    )
    {
    }
    private function prepare(array $arguments, string $userId): array
    {
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
        $card = null;
        $uri = $row['uri'] ?? (string) $card->UID . '.vcf';
        $plan = $this->plans->build(
            'contacts_delete_contact',
            $book,
            $row,
            $before,
            $card,
            $userId
        );
        $plan['permanent'] = true;
        $plan['warning'] = Translator::t('PERMANENT DELETION: Nextcloud has no contact trash bin.');
        $plan['consequence'] = $plan['warning'];
        $plan['backup'] = [
            'required' => true,
            'directory' => $this->backup->directory($book->name),
            'format' => 'vcf',
            'message' => Translator::t('A verified vCard copy will be saved before deletion. If the backup fails, nothing will be deleted.'),
        ];
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
        $book = $prepared['book'];
        $row = $prepared['row'];
        $backupPath = $this->backup->save(
            $userId,
            $book->name,
            $prepared['plan']['before']['name'] ?: $prepared['plan']['before']['uid'],
            $row['data']
        );
        // Check ACL/ownership and ETag again after the backup; If-Match covers changes after this check.
        $currentBook = $this->access->resolve($userId, $arguments['addressbook'], true);
        if ($currentBook->id !== $book->id || $currentBook->ownerPrincipal !== $book->ownerPrincipal) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        $latest = $this->repository->row($book->id, $row['uri']);
        if ($latest['etag'] !== $row['etag']) {
            throw new ToolFailure(FileAccessMessages::conflict());
        }
        $this->dav->delete(
            $userId,
            $book->uri,
            $row['uri'],
            $row['etag']
        );
        return ToolResult::json(
            [
                'uri' => $row['uri'],
                'addressbook' => $book->path,
                'deleted' => true,
                'permanent' => true,
                'recoverable' => false,
                'backupPath' => $backupPath,
                'message' => Translator::t('The contact was permanently deleted. Import the saved vCard through Contacts to recover it.'),
            ]
        );
    }
}
