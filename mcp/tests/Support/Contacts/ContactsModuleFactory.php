<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Contacts;
final class ContactsModuleFactory {
    public static function create($access, $store, $dav, $cards, $guard, $backup, $system): \OCA\Mcp\Modules\Contacts\ContactsModule {
        $repository = new \OCA\Mcp\Modules\Contacts\Cards\ContactRepository($store);
        $plans = new \OCA\Mcp\Modules\Contacts\Execution\ContactPlan($guard, $cards);
        $writer = new \OCA\Mcp\Modules\Contacts\Cards\ContactWriter($dav, $repository, $cards);
        return new \OCA\Mcp\Modules\Contacts\ContactsModule([
            'contacts_list_addressbooks' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\ListAddressBooks(new \OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookReader($access, $system)),
            'contacts_search_contacts' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\SearchContacts(new \OCA\Mcp\Modules\Contacts\Search\ContactSearch($access, $store, $cards, $system)),
            'contacts_read_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\ReadContact(new \OCA\Mcp\Modules\Contacts\Reading\ContactReader($access, $system, $repository, $cards)),
            'contacts_create_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\CreateContact(new \OCA\Mcp\Modules\Contacts\Creation\ContactCreator($access, $repository, $cards, $plans, $writer)),
            'contacts_edit_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\EditContact(new \OCA\Mcp\Modules\Contacts\Editing\ContactEditor($access, $repository, $cards, $plans, $writer)),
            'contacts_delete_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\DeleteContact(new \OCA\Mcp\Modules\Contacts\Deletion\ContactDeleter($access, $repository, $cards, $plans, $backup, $dav)),
        ]);
    }
}
