<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Tools\Calendar\DavResult;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Contacts\ContactAccess;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCA\Mcp\Tools\Contacts\ContactCard;
use OCA\Mcp\Tools\Contacts\ContactDav;
use OCA\Mcp\Tools\Contacts\ContactStore;
use OCA\Mcp\Tools\Contacts\ContactsModule;
use OCA\Mcp\Tools\ToolFailure;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** Verifies contact plans, preserving patches, visibility and guarded native writes. */
final class ContactsModuleTest extends TestCase {
    private const BOOK = '/remote.php/dav/addressbooks/users/alice/personal/';
    private const CARD = "BEGIN:VCARD\r\n"
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
    private ContactStore $store;
    private ContactDav $dav;
    private ContactsModule $module;
    private array $books;
    private array $rows;
    private ContactBackup $backup;

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
        $this->store->method('card')->willReturn(['uri' => 'c1.vcf', 'etag' => '"v1"', 'data' => self::CARD]);
        $this->rows = [['uri' => 'c1.vcf', 'etag' => '"v1"', 'data' => self::CARD]];
        $this->store->method('cards')->willReturnCallback(fn () => $this->rows);
        $this->dav = $this->createMock(ContactDav::class);
        $this->backup = $this->createMock(ContactBackup::class);
        $this->module = new ContactsModule(
            new ContactAccess($this->store),
            $this->store,
            $this->dav,
            new ContactCard(),
            new SharedGuard($this->createMock(IUserManager::class)),
            $this->backup
        );
    }

    public function testSystemBookAndForeignPathsAreNeverVisible(): void {
        $result = $this->json($this->module->call('contacts_list_addressbooks', [], 'alice'));
        self::assertCount(2, $result);
        $this->expectException(ToolFailure::class);
        $this->module->preview(
            'contacts_edit_contact',
            [
                'addressbook' => '/remote.php/dav/addressbooks/users/bob/personal/',
                'uri' => 'c1.vcf',
                'name' => 'New',
            ],
            'alice'
        );
    }

    public function testEditPreviewContainsEveryPropertyAndWritesNothing(): void {
        $this->dav->expects(self::never())->method('update');
        $plan = $this->module->preview(
            'contacts_edit_contact',
            ['addressbook' => self::BOOK, 'uri' => 'c1.vcf', 'name' => 'New Name'],
            'alice'
        );
        self::assertSame('"v1"', $plan['etag']);
        self::assertSame('Old Name', $plan['before']['name']);
        self::assertSame('New Name', $plan['after']['name']);
        self::assertStringContainsString('X-CUSTOM;X-PARAM=keep:untouched', $plan['after']['vcard']);
        self::assertSame($plan['before']['properties'][4], $plan['after']['properties'][4]);
    }

    public function testConfirmedEditUsesCurrentEtagAndPreservesUnknownAndGroupedFields(): void {
        $this->dav->expects(self::once())->method('update')->with(
            'alice',
            'personal',
            'c1.vcf',
            '"v1"',
            self::callback(
                function (string $data): bool {
                    foreach ([
                        'FN:New Name',
                        'N:Name;Old;;;',
                        'EMAIL;TYPE=HOME:home@example.invalid',
                        'ITEM1.TEL;TYPE=CELL:123',
                        'ITEM1.X-ABLABEL:Mobile',
                        'X-CUSTOM;X-PARAM=keep:untouched',
                    ] as $line) {
                        self::assertStringContainsString($line, $data);
                    }
                    return true;
                }
            )
        )->willReturn(new DavResult(204, '"v2"'));
        $this->module->call(
            'contacts_edit_contact',
            [
                'addressbook' => self::BOOK,
                'uri' => 'c1.vcf',
                'name' => 'New Name',
                'confirm' => true,
            ],
            'alice'
        );
    }

    public function testStaleEtagRefusesWithoutDispatch(): void {
        $this->dav->expects(self::never())->method('update');
        $this->expectException(ToolFailure::class);
        $this->module->preview(
            'contacts_edit_contact',
            [
                'addressbook' => self::BOOK,
                'uri' => 'c1.vcf',
                'name' => 'New',
                'etag' => '"old"',
            ],
            'alice'
        );
    }

    public function testSharedWriteRequiresExplicitAcknowledgement(): void {
        $this->dav->expects(self::never())->method('update');
        $args = [
            'addressbook' => '/remote.php/dav/addressbooks/users/alice/team_shared_by_bob/',
            'uri' => 'c1.vcf',
            'name' => 'New',
        ];
        self::assertNotEmpty($this->module->preview('contacts_edit_contact', $args, 'alice')['shared']);
        $this->expectException(ToolFailure::class);
        $this->module->call('contacts_edit_contact', $args + ['confirm' => true], 'alice');
    }

    public function testRevokedAccessIsRecheckedOnExecute(): void {
        $args = ['addressbook' => self::BOOK, 'uri' => 'c1.vcf', 'name' => 'New'];
        $this->module->preview('contacts_edit_contact', $args, 'alice');
        $this->books[0]['readOnly'] = true;
        $this->dav->expects(self::never())->method('update');
        $this->expectException(ToolFailure::class);
        $this->module->call('contacts_edit_contact', $args + ['confirm' => true], 'alice');
    }

    public function testDeleteToolAndDirectUnconfirmedCallOnlyPreviews(): void {
        self::assertContains(
            'contacts_delete_contact',
            array_column($this->module->definitions(), 'name')
        );
        self::assertCount(6, $this->module->definitions());
        $this->dav->expects(self::never())->method('put');
        $result = $this->json(
            $this->module->call(
                'contacts_create_contact',
                ['addressbook' => self::BOOK, 'name' => 'New'],
                'alice'
            )
        );
        self::assertTrue($result['requiresConfirmation']);
    }

    public function testSearchUsesVisibleBookOnlyAndFindsKnownFields(): void {
        self::assertCount(
            1,
            $this->json(
                $this->module->call(
                    'contacts_search_contacts',
                    ['addressbook' => self::BOOK, 'query' => 'home@example'],
                    'alice'
                )
            )['contacts']
        );
        self::assertCount(
            0,
            $this->json(
                $this->module->call(
                    'contacts_search_contacts',
                    ['addressbook' => self::BOOK, 'query' => 'missing'],
                    'alice'
                )
            )['contacts']
        );
    }

    public function testDeleteRequiresVerifiedBackupAndReturnsItsPath(): void {
        $saved = false;
        $this->backup->expects(self::once())->method('save')->with('alice', 'Personal', 'Old Name', self::CARD)->willReturnCallback(
            function () use (&$saved) {
                $saved = true;
                return '/MCP backups/Contacts/Personal/Old Name.20261001-120000.vcf';
            }
        );
        $this->dav->expects(self::once())->method('delete')->with('alice', 'personal', 'c1.vcf', '"v1"')->willReturnCallback(
            function () use (&$saved) {
                self::assertTrue($saved);
                return new DavResult(204);
            }
        );
        $args = ['addressbook' => self::BOOK, 'uri' => 'c1.vcf'];
        $plan = $this->module->preview('contacts_delete_contact', $args, 'alice');
        self::assertSame(self::CARD, $plan['before']['vcard']);
        self::assertTrue($plan['permanent']);
        self::assertFalse($plan['recoverable']);
        self::assertStringContainsString('PERMANENT', $plan['warning']);
        $result = $this->json(
            $this->module->call('contacts_delete_contact', $args + ['confirm' => true], 'alice')
        );
        self::assertStringEndsWith('.vcf', $result['backupPath']);
    }

    public function testBackupFailureNeverDeletesContact(): void {
        $this->backup->method('save')->willThrowException(new ToolFailure('backup failed'));
        $this->dav->expects(self::never())->method('delete');
        $this->expectException(ToolFailure::class);
        $this->module->call(
            'contacts_delete_contact',
            ['addressbook' => self::BOOK, 'uri' => 'c1.vcf', 'confirm' => true],
            'alice'
        );
    }

    public function testEditingOrganizationPreservesDepartmentsAndVcardVersion(): void {
        $cards = new ContactCard();
        $original = $cards->parse(
            str_replace(
                'NOTE:Original',
                'ORG:Example;Department;Unit' . "\r\n" . 'NOTE:Original',
                self::CARD
            )
        );
        $patched = $cards->patch(
            $original,
            ['organization' => 'New Company', 'emails' => ['new@example.invalid']]
        );
        self::assertStringContainsString('ORG:New Company;Department;Unit', $patched->serialize());
        self::assertStringContainsString('EMAIL;TYPE=HOME:new@example.invalid', $patched->serialize());
        self::assertStringContainsString('VERSION:3.0', $patched->serialize());
        self::assertStringContainsString('ORG:Example;Department;Unit', $original->serialize());
    }

    public function testSystemCatalogCannotBeResolvedEvenIfBackendReturnsIt(): void {
        $this->expectException(ToolFailure::class);
        $this->module->call(
            'contacts_read_contact',
            [
                'addressbook' => '/remote.php/dav/addressbooks/users/alice/system/',
                'uri' => 'c1.vcf',
            ],
            'alice'
        );
    }

    public function testPreviewDoesNotCreateBackup(): void {
        $this->backup->expects(self::never())->method('save');
        $this->dav->expects(self::never())->method('delete');
        $result = $this->json(
            $this->module->call(
                'contacts_delete_contact',
                ['addressbook' => self::BOOK, 'uri' => 'c1.vcf'],
                'alice'
            )
        );
        self::assertTrue($result['requiresConfirmation']);
        self::assertSame(self::CARD, $result['before']['vcard']);
    }

    public function testPaginationCanReachContactsBeyondTheFirstPage(): void {
        $this->rows[] = [
            'uri' => 'c2.vcf',
            'etag' => '"v2"',
            'data' => str_replace('UID:c1', 'UID:c2', self::CARD),
        ];
        $first = $this->json(
            $this->module->call(
                'contacts_search_contacts',
                ['addressbook' => self::BOOK, 'limit' => 1],
                'alice'
            )
        );
        self::assertTrue($first['hasMore']);
        self::assertSame(1, $first['nextOffset']);
        $next = $this->json(
            $this->module->call(
                'contacts_search_contacts',
                ['addressbook' => self::BOOK, 'limit' => 1, 'offset' => $first['nextOffset']],
                'alice'
            )
        );
        self::assertSame('c2.vcf', $next['contacts'][0]['uri']);
        self::assertFalse($next['hasMore']);
    }

    public function testReadOrganizationCanBeEditedWithoutDuplicatingDepartments(): void {
        $cards = new ContactCard();
        $original = $cards->parse(
            str_replace(
                'NOTE:Original',
                'ORG:Example;Department;Unit' . "\r\n" . 'NOTE:Original',
                self::CARD
            )
        );
        $item = $cards->item($original);
        self::assertSame('Example', $item['organization']);
        self::assertStringContainsString(
            'ORG:Example;Department;Unit',
            $cards->patch($original, ['organization' => $item['organization']])->serialize()
        );
    }

    private function json(array $result): array {
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
