<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Tools;

use LogicException;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Contacts\Creation\CreateContactHandler;
use OCA\Mcp\Modules\Contacts\Creation\CreateContactPreview;
use OCA\Mcp\Modules\Contacts\Presentation\ContactsSchema;
use RuntimeException;

/** `contacts_create_contact`: a new contact in a writable personal or shared address book. */
final class CreateContact implements WriteTool {
	/**
	 * @param CreateContactHandler $creates the confirmed write
	 * @param CreateContactPreview $plans the plan of the write
	 */
	public function __construct(
		private CreateContactHandler $creates,
		private CreateContactPreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of contacts_create_contact
	 */
	public static function definition(): ToolDefinition {
		return ContactsSchema::definition(
			'contacts_create_contact',
			'create',
			'Create a contact in a writable personal or shared address book. '
				. 'The plan_state of the plan carries the contact UID that confirmation stores; send it back with confirm=true.',
			['addressbook' => ContactsSchema::book(), PlanState::ARGUMENT => PlanState::property()] + ContactsSchema::fields(),
			['addressbook', 'name'],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan when confirm is missing, else the result of the write
	 * @throws ToolFailure when access, the ETag or the shared address book refuses the write
	 * @throws ArgumentValidationException when `plan_state` is missing or does not carry the plan's UID
	 * @throws PlanChanged when the fields are not the ones the plan showed; nothing was written
	 * @throws LogicException when no plan state was provided
	 * @throws RuntimeException when the native write, the backup or the read-back fails
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->creates->handle($context->userId, $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, without confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan, with nothing written
	 * @throws ToolFailure when access or the ETag refuses the write
	 * @throws LogicException when no plan state was provided
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($context->userId, $arguments);
	}
}
