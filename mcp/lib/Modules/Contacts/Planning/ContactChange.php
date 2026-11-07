<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Planning;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Contacts\Backup\ContactBackup;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Locating\AddressBook;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use Sabre\VObject\Component\VCard;

/** The plan shared by the edit and the deletion of a contact: the contact as it is, as it would be, and the risks. */
final class ContactChange {
	/**
	 * @param ContactAccess $access collection visibility, write access and contact reader
	 * @param ContactCard $cards vCard parser and preserving patch builder
	 * @param AddressBookShareNotice $notice notice of an address book owned by somebody else
	 * @param ContactBackup $backup names the folder the deletion copy goes to
	 */
	public function __construct(
		private ContactAccess $access,
		private ContactCard $cards,
		private AddressBookShareNotice $notice,
		private ContactBackup $backup,
	) {}

	/**
	 * Prepares contact access, ETag checks and a complete plan without mutating DAV or Files.
	 *
	 * @param string $name write tool name
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated UID
	 * @return array{book:AddressBook, row:?array, card:?VCard, uri:string, plan:array<string, mixed>} the address book, the current row, the patched card, the URI and the plan
	 * @throws ToolFailure when the book, object or ETag cannot be used safely
	 * @throws InvalidArgumentException when the tool or edit fields are invalid
	 */
	public function prepare(string $name, array $arguments, string $userId): array {
		if (!in_array($name, ['contacts_edit_contact', 'contacts_delete_contact'], true)) {
			throw new InvalidArgumentException('Unknown tool');
		}
		if ($name === 'contacts_edit_contact' && array_intersect(
			array_keys($arguments),
			['name', 'organization', 'title', 'note', 'url', 'emails', 'phones']
		) === []) {
			throw new InvalidArgumentException(Translator::t('Provide at least one field to change.'));
		}
		if ($this->access->isSystemPath($userId, $arguments['addressbook'])) {
			throw new ToolFailure(Translator::t('Account contacts are read-only; ask the administrator.'));
		}
		$book = $this->access->resolve($userId, $arguments['addressbook'], true);
		$row = $this->access->row($book->id, $arguments['uri']);
		if (isset($arguments['etag']) && trim($arguments['etag'], '"') !== trim($row['etag'], '"')) {
			throw new ToolFailure(CommonMessages::conflict());
		}
		$old = $this->cards->parse($row['data']);
		$before = $this->cards->item($old);
		$before['vcard'] = $row['data'];
		$card = $name === 'contacts_delete_contact' ? null : $this->cards->patch($old, $arguments);
		$uri = $row['uri'];
		$plan = [
			'requiresConfirmation' => true,
			'message' => Translator::t('Nothing was changed. Show this plan and ask for explicit confirmation.'),
			'action' => $name,
			'addressbook' => (array) $book,
			'etag' => $row['etag'] ?? null,
			'before' => $before,
			'after' => $card === null ? null : $this->cards->item($card),
			'shared' => $this->notice->shared($book, $userId),
			'recoverable' => false,
			'consequence' => Translator::t(
				'Contacts have no native trash or edit history; review the changes carefully.'
			),
		];
		if ($name === 'contacts_delete_contact') {
			$plan['permanent'] = true;
			$plan['warning'] = Translator::t('PERMANENT DELETION: Nextcloud has no contact trash bin.');
			$plan['consequence'] = $plan['warning'];
			$plan['backup'] = [
				'required' => true,
				'directory' => $this->backup->directory($book->name),
				'format' => 'vcf',
				'message' => Translator::t(
					'A verified vCard copy will be saved before deletion. If the backup fails, nothing will be deleted.'
				),
			];
		}
		return compact('book', 'row', 'card', 'uri', 'plan');
	}
}
