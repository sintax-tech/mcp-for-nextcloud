<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Planning;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;
use OCA\Mcp\Modules\Contacts\Locating\AddressBook;
use OCP\IUserManager;

/**
 * The notice a write plan carries when the address book belongs to somebody else.
 *
 * The notice is always built, whatever the call says about `confirm_shared`: the write checks that flag itself. It goes
 * through the same JSON encoding as a tool result and back, so a name that is not valid UTF-8 comes out with U+FFFD.
 * The message is the one the calendars use, word for word, so every language keeps its translation.
 */
final class AddressBookShareNotice {
	/** @param IUserManager $users resolves the display name of the owner */
	public function __construct(private IUserManager $users) {}

	/**
	 * @param AddressBook $book address book the write would touch
	 * @param string $userId authenticated UID
	 * @return list<array<string, mixed>> the notice, or nothing when the user owns the address book
	 * @throws \JsonException when the encoded notice cannot be read back
	 */
	public function shared(AddressBook $book, string $userId): array {
		if (!$book->ownedByOther($userId)) {
			return [];
		}
		$displayName = $this->users->get($book->ownerId)?->getDisplayName() ?: $book->ownerId;
		$notice = SchemaBuilder::result([
			'requiresConfirmation' => true,
			'scope' => 'shared',
			'owner' => $book->ownerId,
			'ownerDisplayName' => $displayName,
			'resource' => $book->name,
			'message' => Translator::t('The calendar \'%s\' belongs to %s and is shared with you. Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.', [$book->name, $displayName]),
		]);
		return [json_decode($notice['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)];
	}
}
