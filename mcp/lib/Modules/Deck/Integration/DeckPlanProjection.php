<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

/** Projects only the native fields the current plan reads, preserving their evaluation order. */
final class DeckPlanProjection {
	private ?\OCA\Deck\Db\Card $card = null;

	/** @param DeckGatewayInterface $gateway the existing native read boundary */
	public function __construct(private DeckGatewayInterface $gateway) {
	}

	/**
	 * @param string $userId authenticated caller
	 * @param int $cardId card to read
	 * @return array{id:int,stackId:int,lastModified:int} only identity and concurrency fields
	 */
	public function readCard(string $userId, int $cardId): array {
		$this->card = $this->gateway->findCard($userId, $cardId);
		return ['id' => (int)$this->card->getId(), 'stackId' => (int)$this->card->getStackId(), 'lastModified' => (int)$this->card->getLastModified()];
	}

	/**
	 * The fields a write can change, as the plan shows them.
	 *
	 * @param \DateTimeZone|null $zone Zone the due date is shown in, or null to keep the stored one.
	 * @return array{title: string, description: string, duedate: string|null}
	 */
	public function fields( ?\DateTimeZone $zone = null): array {
		return [
			'title' => (string)$this->card->getTitle(),
			'description' => (string)$this->card->getDescription(),
			'duedate' => $this->instant($this->card->getDuedate(), $zone),
		];
	}

	/**
	 * @param mixed $value Value of a Deck `datetime` column.
	 * @param \DateTimeZone|null $zone Zone the instant is shown in, or null to keep the stored one.
	 * @return string|null ISO 8601 instant, or null when the card has no date.
	 */
	private function instant(mixed $value, ?\DateTimeZone $zone = null): ?string {
		if (!$value instanceof \DateTimeInterface) {
			return null;
		}

		return ($zone === null ? $value : \DateTimeImmutable::createFromInterface($value)->setTimezone($zone))
			->format(\DateTimeInterface::ATOM);
	}

	/**
	 * Title of the Deck list a stack id names, or null when it cannot be read.
	 *
	 * The Deck gateway has no single-stack query, and a plan is not a hot path: the visible boards are
	 * walked once and the first list that matches wins.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $stackId Deck list to name.
	 * @return string|null The list title, or null when no visible board holds it.
	 */
	public function listTitle(string $userId, int $stackId): ?string {
		foreach ($this->gateway->listBoards($userId) as $board) {
			foreach ($this->gateway->listStacks($userId, (int)$board->getId()) as $stack) {
				if ((int)$stack->getId() === $stackId) {
					return (string)$stack->getTitle();
				}
			}
		}

		return null;
	}

	/**
	 * @param CardFormatter $formatter formatter of the caller, built at the original evaluation point
	 * @return list<array{uid:string,displayName:string}> unchanged visible assignments
	 */
	public function assignees(CardFormatter $formatter): array {
		return $formatter->card($this->card)['assignedUsers'];
	}

	/**
	 * @param string $userId authenticated caller
	 * @param int $boardId board whose empty stacks the plan names
	 * @return list<string> stack titles in the native order
	 */
	public function stackTitles(string $userId, int $boardId): array {
		return array_map(static fn (\OCA\Deck\Db\Stack $stack): string => (string)$stack->getTitle(), $this->gateway->listStacks($userId, $boardId));
	}
}
