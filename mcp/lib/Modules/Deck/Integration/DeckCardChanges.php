<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Platform\Time\UserTimezone;

/** Creates, updates, moves and deletes a card. A due date is still formatted only by CardDueDate. */
final class DeckCardChanges {
	/** Last position of a stack, so a created card lands at the bottom like the Deck web UI. */
	private const LAST_ORDER = 99999;

	/**
	 * Store the collaborators this area calls. A method binds the caller only when that method did before.
	 *
	 * @param DeckServiceContext $services Lazily resolved Deck services shared with the facade.
	 * @param CardReferenceFilter $references Fresh READ boundary applied to a card Deck just wrote.
	 * @param UserTimezone|null $zones Timezone of the caller, or null when PHP's default decides the day.
	 */
	public function __construct(
		private DeckServiceContext $services,
		private CardReferenceFilter $references,
		private ?UserTimezone $zones
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function createCard(string $userId, int $stackId, string $title, string $description, ?string $duedate, ?int $order = null): Card {
		$this->services->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->services->get(CardService::class);

		// `type` is free text in Deck (only length-checked), so the tools always write `note`.
		return $this->references->filter($userId, $cardService->create($title, $stackId, 'note', $order ?? self::LAST_ORDER, $userId, $description, $this->instant($userId, $duedate)));
	}

	/**
	 * {@inheritDoc}
	 */
	public function updateCard(string $userId, Card $card, string $title, string $description, ?string $duedate): Card {
		$this->services->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->services->get(CardService::class);

		// Deck v1.17.5 nulls `done` when the optional argument is left out, so the current value is
		// sent back wrapped to preserve it; every other field the form requires comes from the card.
		return $this->references->filter($userId, $cardService->update(
			$card->getId(),
			$title,
			$card->getStackId(),
			$card->getType(),
			(string)$card->getOwner(),
			$description,
			$card->getOrder(),
			$this->instant($userId, $duedate),
			null,
			null,
			new OptionalNullableValue($card->getDone()),
			...$this->fieldsAfterDone($cardService, $card),
		));
	}

	/**
	 * The current values of the card fields a Deck release appended to `CardService::update()` after `done`.
	 *
	 * Deck 1.18 (Nextcloud 34) added `?string $startdate` and `?string $color` and writes both on every update, so
	 * leaving them out clears them; Deck 1.19 (Nextcloud 35) made `color` an `OptionalNullableValue`, null leaving it
	 * alone, but still clears a start date left out. Up to 1.17 the signature ends at `done` and the card has neither
	 * field, so nothing is sent. The signature is read from the method itself: named arguments would fail on the
	 * releases without these parameters. Reading stops at a parameter this class does not know, which keeps its default.
	 *
	 * @param object $cardService OCA\Deck\Service\CardService
	 * @param Card $card Card being updated, as Deck loaded it
	 * @return list<mixed> arguments for the parameters after `done`, in order
	 */
	private function fieldsAfterDone(object $cardService, Card $card): array {
		$parameters = (new \ReflectionMethod($cardService, 'update'))->getParameters();
		$names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters);
		$after = array_slice($parameters, (int)array_search('done', $names, true) + 1);

		$arguments = [];
		foreach ($after as $parameter) {
			if ($parameter->getName() === 'startdate') {
				$startdate = $card->getStartdate();
				// Deck reads it back with `new \DateTime($startdate)`; ATOM keeps the instant and its offset.
				$arguments[] = $startdate instanceof \DateTimeInterface ? $startdate->format(DATE_ATOM) : $startdate;
			} elseif ($parameter->getName() === 'color') {
				$type = $parameter->getType();
				$arguments[] = $type instanceof \ReflectionNamedType && $type->getName() === OptionalNullableValue::class
					? new OptionalNullableValue($card->getColor())
					: $card->getColor();
			} else {
				break;
			}
		}
		return $arguments;
	}

	/**
	 * {@inheritDoc}
	 */
	public function moveCard(string $userId, int $cardId, int $stackId, ?int $order): Card {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->services->get(CardMapper::class);
		/** @var CardService $cardService */
		$cardService = $this->services->get(CardService::class);

		if ($order === null) {
			// Reading the destination stack is an access of its own: check READ before the query.
			$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);
			$order = $this->nextOrder($cardMapper->findAll($stackId));
		}

		// `reorder()` checks PERMISSION_EDIT on the card and on the destination stack.
		$cards = $cardService->reorder($cardId, $stackId, $order);
		$moved = null;
		foreach ($cards as $card) {
			if ((int)$card->getId() === $cardId) {
				$moved = $card;
				break;
			}
		}

		return $this->references->filter($userId, $moved ?? $cardService->find($cardId));
	}

	/**
	 * {@inheritDoc}
	 */
	public function deleteCard(string $userId, int $cardId): Card {
		$this->services->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->services->get(CardService::class);

		// Soft delete: Deck stamps `deleted_at` and keeps the row.
		return $this->references->filter($userId, $cardService->delete($cardId));
	}

	/**
	 * Delegate calendar-day interpretation and UTC native storage to the focused date boundary.
	 *
	 * @param string $userId UID whose account timezone interprets the day.
	 * @param string|null $duedate Validated calendar day, account-local time, offset instant, or null.
	 * @return string|null UTC with Z for every supported deadline or preserved native instant, or null.
	 */
	private function instant(string $userId, ?string $duedate): ?string {
		return \OCA\Mcp\Tools\Deck\CardDueDate::forDeck($duedate, $this->zone($userId));
	}

	/**
	 * Timezone the caller reads due dates in.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return \DateTimeZone Their preference, or PHP's default when no resolver was given.
	 */
	private function zone(string $userId): \DateTimeZone {
		return $this->zones?->forUser($userId) ?? new \DateTimeZone(date_default_timezone_get());
	}

	/**
	 * Last free position of a stack, so a card without an explicit order lands at the bottom.
	 *
	 * @param mixed $cards Cards returned by `CardMapper::findAll()`.
	 * @return int Position to use for the moved card.
	 */
	private function nextOrder(mixed $cards): int {
		$highest = -1;
		foreach (is_array($cards) ? $cards : [] as $card) {
			if ($card instanceof Card) {
				$highest = max($highest, (int)$card->getOrder());
			}
		}

		return $highest + 1;
	}
}
