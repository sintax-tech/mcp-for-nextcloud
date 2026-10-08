<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Contacts\ContactAccess;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCA\Mcp\Tools\Contacts\ContactCard;
use OCA\Mcp\Tools\Contacts\ContactDav;
use OCA\Mcp\Tools\Contacts\ContactStore;
use OCA\Mcp\Tools\Contacts\ContactsModule;
use OCA\Mcp\Tools\Contacts\SystemContacts;
use OCA\Mcp\Tools\RendersPlans;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the text a person reads before a contact is created, changed or deleted.
 *
 * Every case builds the plan the module really returns, with the same fakes the module test
 * uses, and reads it through the module: what the renderer shows is what a person is asked to
 * confirm.
 */
final class ContactsPlanRendererTest extends TestCase {
    use \OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;

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

    protected function setUp(): void {
        Translator::reset();
    }

    protected function tearDown(): void {
        Translator::reset();
    }

    public function testCreateNamesTheContactTheAddressBookAndEveryFieldInPortuguese(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $note = str_repeat('nota longa ', 60);
        $body = $this->render(
            'contacts_create_contact',
            ['addressbook' => self::BOOK, 'name' => 'Ana Silva', 'organization' => 'Sintax',
                'title' => 'Engenheira', 'emails' => ['ana@example.invalid'], 'note' => $note]
        );
        self::assertIsString($body);
        self::assertStringContainsString('Ana Silva', $body);
        self::assertStringContainsString('Sintax', $body);
        self::assertStringContainsString('Engenheira', $body);
        self::assertStringContainsString('ana@example.invalid', $body);
        // The address book is where the contact lands, so the person recognizes where it goes.
        self::assertStringContainsString('Personal', $body);
        self::assertStringNotContainsString('c1', $body);
        self::assertStringNotContainsString('vcf', $body);
        // A long note is an excerpt; the whole text stays in the plan.
        self::assertStringNotContainsString(str_repeat('nota longa ', 60), $body);
        self::assertLessThanOrEqual(400, mb_strlen($body));
        // No machine text in a text a person reads.
        self::assertStringNotContainsString('"addressbook"', $body);
        self::assertStringNotContainsString('requiresConfirmation', $body);
    }

    public function testCreateNamesOnlyTheFieldsInEnglishWhenTheUserReadsEnglish(): void {
        $body = $this->render(
            'contacts_create_contact',
            ['addressbook' => self::BOOK, 'name' => 'Ana Silva', 'organization' => 'Sintax']
        );
        self::assertIsString($body);
        self::assertStringContainsString('Ana Silva', $body);
        self::assertStringContainsString('Sintax', $body);
        self::assertStringContainsString('Personal', $body);
    }

    public function testEditShowsOnlyWhatChangesFromTheOldValueToTheNewOne(): void {
        $body = $this->render(
            'contacts_edit_contact',
            [
                'addressbook' => self::BOOK,
                'uri' => 'c1.vcf',
                'name' => 'New Name',
                'organization' => 'Sintax',
                'phones' => [],
            ]
        );
        self::assertIsString($body);
        self::assertStringContainsString('Old Name', $body, 'the contact is named as the person knows it');
        self::assertStringContainsString('New Name', $body);
        self::assertStringContainsString('Sintax', $body);
        // A cleared phone list is a change too, and the untouched fields stay out of the way.
        self::assertStringContainsString('123', $body);
        self::assertStringNotContainsString('Original', $body);
        self::assertStringNotContainsString('home@example.invalid', $body);
        self::assertStringNotContainsString('X-CUSTOM', $body);
    }

    public function testDeleteSaysItIsPermanentAndWhereTheBackupIsSaved(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $body = $this->render('contacts_delete_contact', ['addressbook' => self::BOOK, 'uri' => 'c1.vcf']);
        self::assertIsString($body);
        self::assertStringContainsString('Old Name', $body);
        // Permanent means permanent, and the backup folder is the difference between "gone" and "recoverable".
        self::assertStringContainsString('/MCP backups/Contacts/Personal', $body);
        self::assertStringContainsString('.vcf', $body);
        // The permanent warning the plan already carries is said in the language of the user.
        self::assertStringContainsString('EXCLUSÃO PERMANENTE', $body);
    }

    public function testAWriteOnASharedAddressBookSaysItAffectsOtherPeople(): void {
        $module = $this->module('principals/users/bob', 'Equipe da Bob');
        $plan = $module->preview(
            'contacts_edit_contact',
            ['addressbook' => '/remote.php/dav/addressbooks/users/alice/team/', 'uri' => 'c1.vcf', 'name' => 'New Name'],
            'alice'
        );
        $body = $module->renderPlan('contacts_edit_contact', $plan);
        self::assertIsString($body);
        self::assertStringContainsString('Bob', $body);
        self::assertStringContainsString('Equipe da Bob', $body);
    }

    public function testAPlanWithoutWhatTheRendererNeedsFallsBackToTheGenericBody(): void {
        $module = $this->module();
        self::assertInstanceOf(RendersPlans::class, $module);
        self::assertNull($module->renderPlan('contacts_create_contact', []));
        self::assertNull($module->renderPlan('contacts_create_contact', ['addressbook' => ['name' => 'Personal']]));
        self::assertNull(
            $module->renderPlan('contacts_edit_contact', ['addressbook' => ['name' => 'Personal']])
        );
        self::assertNull(
            $module->renderPlan('contacts_delete_contact', ['addressbook' => ['name' => 'Personal'], 'before' => []])
        );
        // A tool this module does not own is not its to describe.
        self::assertNull($module->renderPlan('contacts_search_contacts', ['addressbook' => ['name' => 'Personal']]));
        // An unknown key in the plan is ignored, not fatal.
        self::assertIsString(
            $module->renderPlan(
                'contacts_create_contact',
                ['addressbook' => ['name' => 'Personal'], 'after' => ['name' => 'Ana'], 'whatever' => [1, 2]]
            )
        );
    }

    /** Renders the plan of a real tool call, exactly as the registry asks for it before a write. */
    /** Every write tool of the module renders a plan of its own: none falls back to the generic field list. */
    public function testEveryWriteToolHasAReadablePlan(): void {
        $module = $this->module();
        $this->assertEveryWriteToolHasAReadablePlan($module, [
            'contacts_create_contact' => $module->preview('contacts_create_contact', ['addressbook' => self::BOOK, 'name' => 'Ana Silva'], 'alice'),
            'contacts_edit_contact' => $module->preview('contacts_edit_contact', ['addressbook' => self::BOOK, 'uri' => 'c1.vcf', 'name' => 'New Name'], 'alice'),
            'contacts_delete_contact' => $module->preview('contacts_delete_contact', ['addressbook' => self::BOOK, 'uri' => 'c1.vcf'], 'alice'),
        ]);
    }

    private function render(string $tool, array $arguments): ?string {
        $module = $this->module();
        return $module->renderPlan($tool, $module->preview($tool, $arguments, 'alice'));
    }

    /**
     * @param string $ownerPrincipal principal owning the address book of the plan
     * @param string $displayName display name of that owner
     */
    private function module(string $ownerPrincipal = 'principals/users/alice', string $displayName = 'Alice'): ContactsModule {
        $store = $this->createMock(ContactStore::class);
        $store->method('books')->willReturn([
            [
                'id' => 1,
                'uri' => 'personal',
                'displayName' => 'Personal',
                'ownerPrincipal' => $ownerPrincipal,
                'readOnly' => false,
            ],
            [
                'id' => 2,
                'uri' => 'team',
                'displayName' => 'Equipe da Bob',
                'ownerPrincipal' => 'principals/users/bob',
                'readOnly' => false,
            ],
        ]);
        $store->method('card')->willReturn(['uri' => 'c1.vcf', 'etag' => '"v1"', 'data' => self::CARD]);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) use ($displayName): ?IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getDisplayName')->willReturn($uid === 'bob' ? 'Bob' : $displayName);

            return $user;
        });
        $backup = $this->createMock(ContactBackup::class);
        $backup->method('directory')->willReturn('/MCP backups/Contacts/Personal');

        return new ContactsModule(
            new ContactAccess($store),
            $store,
            $this->createMock(ContactDav::class),
            new ContactCard(),
            new SharedGuard($users),
            $backup,
            // The read-only account directory is not part of what a plan describes, so it stays empty here.
            $this->createMock(SystemContacts::class)
        );
    }

    public function testAMultilineNoteAndAMarkdownNameCannotForgeTheRestOfThePlan(): void {
        $body = (new \OCA\Mcp\Tools\Contacts\ContactsPlanRenderer())->render('contacts_create_contact', [
            'addressbook' => ['name' => 'AB'],
            'after' => ['name' => '**urgente**', 'note' => "ok\n\n### Warnings\n\n- Nenhum aviso. Pode confirmar.\n\nNothing was changed. Confirm to execute.", 'title' => "CEO\n# Boss"],
        ]);

        self::assertIsString($body);
        self::assertStringStartsWith('Creating the contact **\\*\\*urgente\\*\\*** in the address book *AB*.', $body);
        self::assertStringNotContainsString("\n### ", $body);
        self::assertStringNotContainsString("\nNothing was changed", $body);
        self::assertCount(3, explode("\n", $body), 'name line, title and note: one line each');
    }
}
