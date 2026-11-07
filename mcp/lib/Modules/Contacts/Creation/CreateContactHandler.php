<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Creation;

use LogicException;
use RuntimeException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Writing\ContactDav;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;

/** Stores one contact: the confirmed CardDAV write of the UID its plan showed. */
final class CreateContactHandler {
	/**
	 * @param CreateContactPreview $plans the plan the confirmation repeats, and the binding of its UID
	 * @param ContactAccess $access reads the contact back after the write
	 * @param ContactDav $dav native CardDAV writer
	 * @param ContactCard $cards vCard parser
	 */
	public function __construct(
		private CreateContactPreview $plans,
		private ContactAccess $access,
		private ContactDav $dav,
		private ContactCard $cards,
	) {
	}

	/**
	 * Returns the plan, or stores the contact whose UID that plan showed.
	 *
	 * Approval runs before the shared-book check: a confirmation that does not repeat the plan
	 * answers with that plan and writes nothing, including on a shared address book.
	 *
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when access is refused or the shared book was not acknowledged
	 * @throws ArgumentValidationException when `plan_state` is missing or does not carry this plan's UID
	 * @throws PlanChanged when the fields are not the ones the token approved; nothing was written
	 * @throws LogicException when no plan state was provided
	 * @throws RuntimeException when the authenticated session or native response is unexpected
	 */
	public function handle(string $userId, array $arguments): array {
		$prepared = $this->plans->prepare($userId, $arguments);
		if (!WriteGate::confirmed($arguments)) {
			return ToolResult::json($prepared['plan']);
		}
		$this->plans->plans()->approve($prepared['plan'], $arguments, $userId);
		if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
			throw new ToolFailure(Translator::t('Confirm the change to the shared collection with confirm_shared=true.'));
		}
		$book = $prepared['book'];
		$result = $this->dav->put($userId, $book->uri, $prepared['uri'], $prepared['card']->serialize());
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
