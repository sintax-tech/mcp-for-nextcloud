<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema as Schema;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Modules\Contacts\Backup\ContactBackupText;
use OCA\Mcp\Modules\Contacts\Creation\{CreateContactHandler, CreateContactPreview};
use OCA\Mcp\Modules\Contacts\Deletion\{DeleteContactHandler, DeleteContactPreview};
use OCA\Mcp\Modules\Contacts\Editing\{EditContactHandler, EditContactPreview};
use OCA\Mcp\Modules\Contacts\Presentation\ContactsPlanRenderer;
use OCA\Mcp\Modules\Contacts\Reading\{ListAddressBooksHandler, ReadContactHandler, SearchContactsHandler};
use RuntimeException;

/** Personal CardDAV contacts; writes use the same native ACL/validation pipeline as the web UI. */
final class ContactsModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
	/**
	 * Receives the work of each tool: the three reads, and the plan and the effect of each write.
	 *
	 * @param ListAddressBooksHandler $lists own and shared address books plus the accounts catalog
	 * @param SearchContactsHandler $searches personal contacts first, then the accounts
	 * @param ReadContactHandler $reads one contact, or the public fields of one account
	 * @param CreateContactPreview $createPlans the creation plan and the UID it shows
	 * @param CreateContactHandler $creates the confirmed write of that UID
	 * @param EditContactPreview $editPlans the edit plan
	 * @param EditContactHandler $edits the confirmed edit
	 * @param DeleteContactPreview $deletePlans the deletion plan
	 * @param DeleteContactHandler $deletes the verified backup followed by the permanent deletion
	 * @return void
	 */
	public function __construct(
		private ListAddressBooksHandler $lists,
		private SearchContactsHandler $searches,
		private ReadContactHandler $reads,
		private CreateContactPreview $createPlans,
		private CreateContactHandler $creates,
		private EditContactPreview $editPlans,
		private EditContactHandler $edits,
		private DeleteContactPreview $deletePlans,
		private DeleteContactHandler $deletes,
	) {}

	/**
	 * Declares tool schemas and operation kinds used to decide whether calls need confirmation.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function definitions(): array {
		$book = Schema::text('Address book path returned by List address books.', 1, 1024);
		$uri = Schema::text('Contact object URI returned by Search contacts.', 1, 255);
		$fields = [];
		foreach (['name', 'organization', 'title', 'note', 'url'] as $field) {
			$fields[$field] = Schema::text(
				'Contact ' . $field . '. Omit to preserve its existing value.',
				$field === 'name' ? 1 : 0,
				$field === 'note' ? 65536 : 1024
			);
		}
		$fields['organization']['description'] = 'Organization company name (first ORG component); '
			. 'existing departments and units are preserved.';
		foreach (['emails', 'phones'] as $field) {
			$fields[$field] = [
				'type' => 'array',
				'maxItems' => 50,
				'items' => Schema::text('Value.', 1, 1024),
				'description' => 'Replace the ' . $field . ' in order; existing parameters are preserved. '
					. 'Omit to keep all values, [] to clear them.',
			];
		}
		return [
			Schema::definition(
				'contacts',
				'contacts',
				'contacts_list_addressbooks',
				'List your own and shared address books plus, when the admin exposes it, the read-only '
					. 'accounts catalog of the instance.',
				'read',
				[]
			),
			Schema::definition(
				'contacts',
				'contacts',
				'contacts_search_contacts',
				'Search contacts by name, email, phone or organization in one address book or, when '
					. '"addressbook" is omitted, in all of them including the read-only accounts of the instance '
					. '(colleagues). Account results carry accountId, the user ID to use for invitations, '
					. 'attendees, Deck and Talk. An empty query lists personal contacts only; accounts need a '
					. 'search term of at least 2 characters.',
				'read',
				[
					'addressbook' => Schema::text(
						'Address book path returned by List address books. Omit to search all of them.',
						1,
						1024
					),
					'query' => Schema::text('Case-insensitive search text.', 0, 1024),
					'limit' => Schema::limit(),
					'offset' => Schema::offset(),
				]
			),
			Schema::definition(
				'contacts',
				'contacts',
				'contacts_read_contact',
				'Read a contact including all vCard properties and its ETag. A contact of the accounts '
					. 'catalog returns only its public fields and accountId, without vCard or ETag.',
				'read',
				['addressbook' => $book, 'uri' => $uri],
				['addressbook', 'uri']
			),
			Schema::definition(
				'contacts',
				'contacts',
				'contacts_create_contact',
				'Create a contact in a writable personal or shared address book. '
					. 'The plan_state of the plan carries the contact UID that confirmation stores; send it back with confirm=true.',
				'create',
				['addressbook' => $book, PlanState::ARGUMENT => PlanState::property()] + $fields,
				['addressbook', 'name']
			),
			Schema::definition(
				'contacts',
				'contacts',
				'contacts_edit_contact',
				'Edit only supplied contact fields, preserving unknown vCard properties.',
				'edit',
				['addressbook' => $book, 'uri' => $uri, 'etag' => Schema::etag()] + $fields,
				['addressbook', 'uri']
			),
			Schema::definition(
				'contacts',
				'contacts',
				'contacts_delete_contact',
				'Permanently delete a contact after saving a verified vCard backup in your Files. '
					. 'Nextcloud has no contact trash.',
				'delete',
				['addressbook' => $book, 'uri' => $uri, 'etag' => Schema::etag()],
				['addressbook', 'uri']
			),
		];
	}

	/**
	 * Describes the confirmation workflow, data protection and recovery limitations.
	 *
	 * @return list<string>
	 */
	public function guideNotes(): array {
		return [
			'List address books first; your own and shared address books are exposed, plus the '
				. 'read-only accounts catalog when the admin allows it. Searching without an address '
				. 'book also covers the accounts of the instance (colleagues, with a term of at least 2 '
				. 'characters, following the admin user-enumeration settings); each account result has '
				. 'accountId, the ID to use for invitations. Use users_search to look for accounts only. '
				. 'Account contacts can never be created, edited or deleted.',
			'Search an address book to obtain contact object URIs, then read a contact before '
				. 'editing it. Unknown vCard properties are preserved; the preview includes every '
				. 'property before and after.',
			'Writes first show a plan without changing anything. Wait for an explicit yes, repeat '
				. 'with confirm=true, and acknowledge another owner with confirm_shared=true. '
				. 'Creating a contact repeats the plan_state of that plan: it carries the UID that is stored. The '
				. 'optional etag prevents overwriting a newer edit.',
			'Contact deletion is PERMANENT in Nextcloud: there is no contact trash. After '
				. ContactBackupText::destination()
				. 'native CardDAV DELETE. Backup failure prevents deletion; the result gives the backup '
				. 'path. Import the .vcf through Contacts to recover it.',
			'These tools are hidden when the Contacts app is disabled. Writing grants start disabled.',
		];
	}

	/**
	 * Builds a real before/after plan without changing the DAV collection.
	 *
	 * @param string $name registered tool name
	 * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed>
	 * @throws ToolFailure when access, concurrency or recovery checks refuse the operation
	 * @throws InvalidArgumentException when the tool or supplied fields are invalid
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return match ($name) {
			'contacts_create_contact' => $this->createPlans->plan($userId, $arguments),
			'contacts_edit_contact' => $this->editPlans->plan($userId, $arguments),
			'contacts_delete_contact' => $this->deletePlans->plan($userId, $arguments),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}

	/**
	 * Describes the plan as the text the person confirms, in their own language.
	 *
	 * @param string $name registered write tool name
	 * @param array<string, mixed> $plan the plan preview() returned for that tool
	 * @return string|null Markdown body, or null when this renderer has nothing to say about it
	 */
	public function renderPlan(string $name, array $plan): ?string {
		// Built on demand and not injected: a contact plan carries no date, so the renderer needs
		// nothing this module does not already hold, and a constructor parameter would reach every caller.
		return (new ContactsPlanRenderer())->render($name, $plan);
	}

	/**
	 * Reads data or executes a prepared write after confirmation and access checks.
	 *
	 * @param string $name registered tool name
	 * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when access, concurrency or recovery checks refuse the operation
	 * @throws InvalidArgumentException when the tool or supplied fields are invalid
	 * @throws RuntimeException when the native write or its read-back verification fails
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return match ($name) {
			'contacts_list_addressbooks' => $this->lists->handle($userId, $arguments),
			'contacts_search_contacts' => $this->searches->handle($userId, $arguments),
			'contacts_read_contact' => $this->reads->handle($userId, $arguments),
			'contacts_create_contact' => $this->creates->handle($userId, $arguments),
			'contacts_edit_contact' => $this->edits->handle($userId, $arguments),
			'contacts_delete_contact' => $this->deletes->handle($userId, $arguments),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}
}
