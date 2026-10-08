<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Contacts\ContactAccess;
use OCA\Mcp\Tools\Contacts\ContactBackup;
use OCA\Mcp\Tools\Contacts\ContactCard;
use OCA\Mcp\Tools\Contacts\ContactDav;
use OCA\Mcp\Tools\Contacts\ContactStore;
use OCA\Mcp\Tools\Contacts\ContactsModule;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Verifies Contacts app availability and deny-by-default write grants. */
final class ContactsPolicyTest extends TestCase {
    public function testWritesAreHiddenByDefaultAndRevokedGrantIsCheckedOnConfirmedCall(): void {
        [$registry, $policy] = $this->registry();
        $names = array_column($registry->list('alice'), 'name');
        self::assertContains('contacts_read_contact', $names);
        foreach ([
            'contacts_create_contact',
            'contacts_edit_contact',
            'contacts_delete_contact',
        ] as $name) {
            self::assertNotContains($name, $names);
        }
        $policy->setGrant('alice', 'contacts', 'delete', true);
        self::assertContains('contacts_delete_contact', array_column($registry->list('alice'), 'name'));
        $policy->setGrant('alice', 'contacts', 'delete', false);
        $this->expectException(InvalidArgumentException::class);
        $registry->call(
            'contacts_delete_contact',
            [
                'addressbook' => '/remote.php/dav/addressbooks/users/alice/personal/',
                'uri' => 'c1.vcf',
                'confirm' => true,
            ],
            'alice'
        );
    }

    public function testDisabledContactsAppHidesAllContactTools(): void {
        [$registry] = $this->registry(false);
        self::assertSame(['mcp_guide'], array_column($registry->list('alice'), 'name'));
    }

    private function registry(bool $enabled = true): array {
        $store = $this->createMock(ContactStore::class);
        $store->expects(self::never())->method('card');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));
        $module = new ContactsModule(
            new ContactAccess($store),
            $store,
            $this->createMock(ContactDav::class),
            new ContactCard(),
            new SharedGuard($users),
            $this->createMock(ContactBackup::class),
            $this->createMock(\OCA\Mcp\Tools\Contacts\SystemContacts::class)
        );
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->with('contacts')->willReturn($enabled);
        $policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        return [
            new ToolRegistry([$module], $policy, $apps, $users, new NullLogger()),
            $policy,
        ];
    }
}
