<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Creation;

use LogicException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\Locating\AddressBook;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Modules\Contacts\Planning\AddressBookShareNotice;
use Sabre\VObject\Component\VCard;

/**
 * The plan of `contacts_create_contact`, with the UID confirmation will store.
 *
 * Account contacts are refused before a UID is drawn, so a missing {@see PlanState} does not change that refusal.
 */
final class CreateContactPreview {
	/**
	 * @param ContactAccess $access collection visibility and write-access resolver
	 * @param ContactCard $cards vCard builder
	 * @param AddressBookShareNotice $notice notice of an address book owned by somebody else
	 * @param PlanState|null $states HMAC of the creation plan; null only when a test never creates
	 */
	public function __construct(
		private ContactAccess $access,
		private ContactCard $cards,
		private AddressBookShareNotice $notice,
		private ?PlanState $states = null,
	) {}

	/**
	 * Builds the creation plan, including the UID confirmation will store.
	 *
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array<string, mixed> the plan; nothing is written
	 * @throws ToolFailure when the address book is the accounts catalog, hidden or not writable
	 * @throws LogicException when no plan state was provided
	 */
	public function plan(string $userId, array $arguments): array {
		return $this->prepare($userId, $arguments)['plan'];
	}

	/**
	 * Resolves the address book and builds the card and the plan. Nothing is written.
	 *
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array{book:AddressBook, card:VCard, uri:string, plan:array<string, mixed>} the address book, the new card, its URI and the plan
	 * @throws ToolFailure when the address book is the accounts catalog, hidden or not writable
	 * @throws LogicException when no plan state was provided
	 */
	public function prepare(string $userId, array $arguments): array {
		if ($this->access->isSystemPath($userId, $arguments['addressbook'])) {
			throw new ToolFailure(Translator::t('Account contacts are read-only; ask the administrator.'));
		}
		$plans = $this->plans();
		$book = $this->access->resolve($userId, $arguments['addressbook'], true);
		$card = $this->cards->create($arguments, $plans->uid($arguments));
		$uri = (string)$card->UID . '.vcf';
		$plan = $plans->stamp([
			'requiresConfirmation' => true,
			'message' => Translator::t('Nothing was changed. Show this plan and ask for explicit confirmation.'),
			'action' => 'contacts_create_contact',
			'addressbook' => (array)$book,
			'etag' => null,
			'before' => null,
			'after' => $this->cards->item($card),
			'shared' => $this->notice->shared($book, $userId),
			'recoverable' => false,
			'consequence' => Translator::t('Contacts have no native trash or edit history; review the changes carefully.'),
		], $userId, $arguments);

		return compact('book', 'card', 'uri', 'plan');
	}

	/**
	 * @return ContactCreatePlan binding of the creation UID
	 * @throws LogicException when no plan state was provided
	 */
	public function plans(): ContactCreatePlan {
		if ($this->states === null) {
			throw new LogicException('Contact creation needs a plan state');
		}

		return new ContactCreatePlan($this->states);
	}
}
