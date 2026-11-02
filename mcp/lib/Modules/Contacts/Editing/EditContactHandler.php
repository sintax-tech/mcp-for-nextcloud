<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Editing;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Modules\Contacts\Planning\ContactChange;
use OCA\Mcp\Modules\Contacts\Writing\ContactDav;
use RuntimeException;

/** Writes the edit of one contact through a conditional CardDAV update, once the plan was confirmed. */
final class EditContactHandler {
	/**
	 * @param ContactChange $changes the plan the confirmation repeats
	 * @param ContactAccess $access reads the contact back after the write
	 * @param ContactDav $dav native CardDAV writer
	 * @param ContactCard $cards vCard parser
	 */
	public function __construct(
		private ContactChange $changes,
		private ContactAccess $access,
		private ContactDav $dav,
		private ContactCard $cards,
	) {}

	/**
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments and explicit confirmations
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} the plan without confirm, else the
	 *     contact as stored
	 * @throws ToolFailure when access, the ETag or the shared address book refuses the edit
	 * @throws InvalidArgumentException when no field to change was given
	 * @throws RuntimeException when the native write or its read-back fails
	 */
	public function handle(string $userId, array $arguments): array {
		$prepared = $this->changes->prepare('contacts_edit_contact', $arguments, $userId);
		if (!WriteGate::confirmed($arguments)) {
			return ToolResult::json($prepared['plan']);
		}
		if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
			throw new ToolFailure(
				Translator::t('Confirm the change to the shared collection with confirm_shared=true.')
			);
		}
		$book = $prepared['book'];
		$row = $prepared['row'];
		$result = $this->dav->update($userId, $book->uri, $row['uri'], $row['etag'], $prepared['card']->serialize());
		// Read back because native plugins may normalize the card and omit the response ETag.
		$written = $this->access->row($book->id, $prepared['uri']);
		return ToolResult::json(
			$this->cards->item($this->cards->parse($written['data'])) + [
				'uri' => $written['uri'],
				'etag' => $written['etag'],
				'addressbook' => $book->path,
				'status' => $result->status,
			]
		);
	}
}
