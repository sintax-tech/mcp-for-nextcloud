<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Tools;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Contacts\Editing\EditContactHandler;
use OCA\Mcp\Modules\Contacts\Editing\EditContactPreview;
use OCA\Mcp\Modules\Contacts\Presentation\ContactsSchema;
use RuntimeException;

/** `contacts_edit_contact`: only the supplied fields of one contact. */
final class EditContact implements WriteTool {
	/**
	 * @param EditContactHandler $edits the confirmed write
	 * @param EditContactPreview $plans the plan of the write
	 */
	public function __construct(
		private EditContactHandler $edits,
		private EditContactPreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of contacts_edit_contact
	 */
	public static function definition(): ToolDefinition {
		return ContactsSchema::definition(
			'contacts_edit_contact',
			'edit',
			'Edit only supplied contact fields, preserving unknown vCard properties.',
			['addressbook' => ContactsSchema::book(), 'uri' => ContactsSchema::uri(), 'etag' => ContactsSchema::etag()] + ContactsSchema::fields(),
			['addressbook', 'uri'],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan when confirm is missing, else the result of the write
	 * @throws ToolFailure when access, the ETag or the shared address book refuses the write
	 * @throws InvalidArgumentException when no field to change was given
	 * @throws RuntimeException when the native write, the backup or the read-back fails
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->edits->handle($context->userId, $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, without confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan, with nothing written
	 * @throws ToolFailure when access or the ETag refuses the write
	 * @throws InvalidArgumentException when no field to change was given
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($context->userId, $arguments);
	}
}
