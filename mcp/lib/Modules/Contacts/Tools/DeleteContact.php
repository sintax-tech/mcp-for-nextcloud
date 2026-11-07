<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Contacts\Deletion\DeleteContactHandler;
use OCA\Mcp\Modules\Contacts\Deletion\DeleteContactPreview;
use OCA\Mcp\Modules\Contacts\Presentation\ContactsSchema;
use RuntimeException;

/** `contacts_delete_contact`: the permanent deletion of one contact, after its verified vCard backup. */
final class DeleteContact implements WriteTool {
	/**
	 * @param DeleteContactHandler $deletions the confirmed write
	 * @param DeleteContactPreview $plans the plan of the write
	 */
	public function __construct(
		private DeleteContactHandler $deletions,
		private DeleteContactPreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of contacts_delete_contact
	 */
	public static function definition(): ToolDefinition {
		return ContactsSchema::definition(
			'contacts_delete_contact',
			'delete',
			'Permanently delete a contact after saving a verified vCard backup in your Files. '
				. 'Nextcloud has no contact trash.',
			['addressbook' => ContactsSchema::book(), 'uri' => ContactsSchema::uri(), 'etag' => ContactsSchema::etag()],
			['addressbook', 'uri'],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan when confirm is missing, else the result of the write
	 * @throws ToolFailure when access, the ETag or the shared address book refuses the write
	 * @throws RuntimeException when the native write, the backup or the read-back fails
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->deletions->handle($context->userId, $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, without confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan, with nothing written
	 * @throws ToolFailure when access or the ETag refuses the write
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($context->userId, $arguments);
	}
}
