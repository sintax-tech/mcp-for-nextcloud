<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Contacts;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCA\Mcp\Modules\Contacts\Deletion\ContactBackup;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Platform\Dav\CardDav\ContactDav;
use OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore;
use OCA\Mcp\Modules\Contacts\ContactsModule;
use OCA\Mcp\Modules\Contacts\Directory\AccountDirectory as SystemContacts;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

abstract class ContactsTestCase extends TestCase {
    protected const BOOK = '/remote.php/dav/addressbooks/users/alice/personal/';
    protected const CARD = "BEGIN:VCARD\r\n"
        . "VERSION:3.0\r\n"
        . "UID:c1\r\n"
        . "FN:Old Name\r\n"
        . "N:Name;Old;;;\r\n"
        . "EMAIL;TYPE=HOME:home@example.invalid\r\n"
        . "item1.TEL;TYPE=CELL:123\r\n"
        . "item1.X-ABLABEL:Mobile\r\n"
        . "X-CUSTOM;X-PARAM=keep:untouched\r\n"
        . "NOTE:Original\r\n"
        . "END:VCARD\r\n";
    protected ContactStore $store;
    protected ContactDav $dav;
    protected ContactsModule $module;
    protected array $books;
    protected array $rows;
    /** @var array{uri:string, etag:string, data:string} what the store answers for the contact, changeable to play another client */
    protected array $card;
    protected ContactBackup $backup;
    protected SystemContacts $system;

    protected function setUp(): void {
        $this->books = [
            [
                'id' => 1,
                'uri' => 'personal',
                'displayName' => 'Personal',
                'ownerPrincipal' => 'principals/users/alice',
                'readOnly' => false,
            ],
            [
                'id' => 2,
                'uri' => 'team_shared_by_bob',
                'displayName' => 'Team',
                'ownerPrincipal' => 'principals/users/bob',
                'readOnly' => false,
            ],
            [
                'id' => 3,
                'uri' => 'system',
                'displayName' => 'System',
                'ownerPrincipal' => 'principals/system/system',
                'readOnly' => false,
            ],
        ];
        $this->store = $this->createMock(ContactStore::class);
        $this->store->method('books')->willReturnCallback(fn () => $this->books);
        $this->card = ['uri' => 'c1.vcf', 'etag' => '"v1"', 'data' => self::CARD];
        $this->store->method('card')->willReturnCallback(fn (): array => $this->card);
        $this->rows = [['uri' => 'c1.vcf', 'etag' => '"v1"', 'data' => self::CARD]];
        $this->store->method('cards')->willReturnCallback(fn (int $bookId): array => $bookId === 1 ? $this->rows : []);
        $this->dav = $this->createMock(ContactDav::class);
        $this->backup = $this->createMock(ContactBackup::class);
        $this->system = $this->createMock(SystemContacts::class);
        $this->module = \OCA\Mcp\Tests\Support\Contacts\ContactsModuleFactory::create(
            new ContactAccess($this->store),
            $this->store,
            $this->dav,
            new ContactCard(),
            new SharedGuard($this->createMock(IUserManager::class)),
            $this->backup,
            $this->system
        );
    }
}
