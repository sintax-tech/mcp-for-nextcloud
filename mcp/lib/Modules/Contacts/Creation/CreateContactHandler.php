<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Creation;

use Closure;
use LogicException;
use RuntimeException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Calendar\Locating\Calendar;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Creation\ContactCreatePlan;
use OCA\Mcp\Modules\Contacts\Writing\ContactDav;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use Sabre\VObject\Component\VCard;

/**
 * Creates one contact: the plan, the UID it shows, and the confirmed CardDAV write of that same UID.
 *
 * The module only forwards this tool here. Account contacts are refused before a UID is drawn, so a
 * missing {@see PlanState} does not change that refusal.
 */
final class CreateContactHandler {
	/**
	 * @param ContactAccess $access collection visibility and write-access resolver
	 * @param ContactDav $dav native CardDAV writer
	 * @param ContactCard $cards vCard builder
	 * @param SharedGuard $guard shared-owner confirmation policy
	 * @param PlanState|null $states HMAC of the creation plan; null only when a test never creates
	 * @param Closure(int, string): array{uri:string, etag:string, data:string} $readRow contact read-back after the write
	 */
	public function __construct(
		private ContactAccess $access,
		private ContactDav $dav,
		private ContactCard $cards,
		private SharedGuard $guard,
		private ?PlanState $states,
		private Closure $readRow,
	) {
	}

	/**
	 * Builds the creation plan, including the UID confirmation will store.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> plan, with `plan_state` carrying the UID
	 * @throws ToolFailure when the address book is the accounts catalog, hidden or not writable
	 * @throws LogicException when no plan state was provided
	 */
	public function preview(array $arguments, string $userId): array {
		return $this->prepare($arguments, $userId)['plan'];
	}

	/**
	 * Returns the plan, or stores the contact whose UID that plan showed.
	 *
	 * Approval runs before the shared-book check: a confirmation that does not repeat the plan
	 * answers with that plan and writes nothing, including on a shared address book.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when access is refused or the shared book was not acknowledged
	 * @throws ArgumentValidationException when `plan_state` is missing or does not carry this plan's UID
	 * @throws PlanChanged when the fields are not the ones the token approved; nothing was written
	 * @throws LogicException when no plan state was provided
	 * @throws RuntimeException when the authenticated session or native response is unexpected
	 */
	public function handle(array $arguments, string $userId): array {
		$prepared = $this->prepare($arguments, $userId);
		if (!WriteGate::confirmed($arguments)) {
			return ToolResult::json($prepared['plan']);
		}
		$this->plans()->approve($prepared['plan'], $arguments, $userId);
		if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
			throw new ToolFailure(Translator::t('Confirm the change to the shared collection with confirm_shared=true.'));
		}
		$book = $prepared['book'];
		$result = $this->dav->put($userId, $book->uri, $prepared['uri'], $prepared['card']->serialize());
		$written = ($this->readRow)($book->id, $prepared['uri']);

		return ToolResult::json(
			$this->cards->item($this->cards->parse($written['data'])) + [
				'uri' => $written['uri'],
				'etag' => $written['etag'],
				'addressbook' => $book->path,
				'status' => $result->status,
			]
		);
	}

	/**
	 * Resolves the address book and builds the card and the plan. Nothing is written.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array{book:Calendar, card:VCard, uri:string, plan:array<string, mixed>}
	 * @throws ToolFailure when the address book is the accounts catalog, hidden or not writable
	 * @throws LogicException when no plan state was provided
	 */
	private function prepare(array $arguments, string $userId): array {
		if ($this->access->isSystemPath($userId, $arguments['addressbook'])) {
			throw new ToolFailure(Translator::t('Account contacts are read-only; ask the administrator.'));
		}
		$plans = $this->plans();
		$book = $this->access->resolve($userId, $arguments['addressbook'], true);
		$card = $this->cards->create($arguments, $plans->uid($arguments));
		$uri = (string)$card->UID . '.vcf';
		$notice = $this->guard->confirm($book, $userId, []);
		$shared = $notice === null ? [] : [json_decode($notice['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)];
		$plan = $plans->stamp([
			'requiresConfirmation' => true,
			'message' => Translator::t('Nothing was changed. Show this plan and ask for explicit confirmation.'),
			'action' => 'contacts_create_contact',
			'addressbook' => (array)$book,
			'etag' => null,
			'before' => null,
			'after' => $this->cards->item($card),
			'shared' => $shared,
			'recoverable' => false,
			'consequence' => Translator::t('Contacts have no native trash or edit history; review the changes carefully.'),
		], $userId, $arguments);

		return compact('book', 'card', 'uri', 'plan');
	}

	/**
	 * @return ContactCreatePlan binding of the creation UID
	 * @throws LogicException when no plan state was provided
	 */
	private function plans(): ContactCreatePlan {
		if ($this->states === null) {
			throw new LogicException('Contact creation needs a plan state');
		}

		return new ContactCreatePlan($this->states);
	}
}
