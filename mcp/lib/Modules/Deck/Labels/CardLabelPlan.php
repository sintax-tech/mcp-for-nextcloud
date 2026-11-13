<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

use Closure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;

/**
 * The labels of a card about to be created, sent like the labels of a GitHub issue: titles or IDs.
 *
 * An ID must be a label of the board of the list. A title finds a label of that board ({@see LabelInput::matches()});
 * a title the board does not have is created with {@see LabelInput::defaultColor()}. Creating needs the deck.edit grant
 * and native MANAGE on the board, both checked before the card exists, so a refusal leaves nothing behind.
 */
final class CardLabelPlan {
	/**
	 * @param DeckGatewayInterface $gateway Deck access.
	 * @param array<string, mixed> $arguments Validated deck_create_card arguments.
	 * @param string $userId Authenticated caller.
	 * @param Closure(): bool $mayCreate Whether the caller holds the deck.edit grant, asked only when needed.
	 * @return array{boardId: int, assign: list<array{id: int, boardId: int, title: string, color: string}>, create: list<array{title: string, color: string}>}|null
	 *     Null when the call sends no labels.
	 * @throws \Throwable When an item is invalid, unknown or ambiguous, or creation is not allowed.
	 */
	public static function prepare(DeckGatewayInterface $gateway, array $arguments, string $userId, Closure $mayCreate): ?array {
		$requested = self::requested($arguments['labels'] ?? []);
		if ($requested === []) {
			return null;
		}
		$board = $gateway->boardLabels()->stackLabels($userId, (int)$arguments['stackId']);
		$plan = self::resolve($requested, $board['labels']);
		if ($plan['create'] !== []) {
			$titles = array_column($plan['create'], 'title');
			if (!$mayCreate()) {
				throw new DeckRefusalException(DeckLabelMessages::creationNeedsGrant($titles));
			}
			if (!$board['manage']) {
				throw new DeckRefusalException(DeckLabelMessages::creationNeedsManage($titles));
			}
		}
		return ['boardId' => $board['boardId']] + $plan;
	}

	/**
	 * @param mixed $raw The labels argument, already a list of strings and positive integers by schema.
	 * @return list<string|int> Trimmed titles and IDs, without repeats.
	 * @throws ArgumentValidationException When an item is not a valid title or ID.
	 */
	public static function requested(mixed $raw): array {
		if (!is_array($raw) || !array_is_list($raw) || count($raw) > LabelInput::MAX_CARD_LABELS) {
			throw new ArgumentValidationException('Invalid argument: labels', 'labels', DeckLabelMessages::errorCardLabels());
		}
		$out = [];
		foreach ($raw as $item) {
			if (is_int($item) && $item > 0) {
				$out['#' . $item] = $item;
			} elseif (is_string($item)) {
				$title = LabelInput::title($item, 'labels');
				$out['t' . mb_strtolower($title)] ??= $title;
			} else {
				throw new ArgumentValidationException('Invalid argument: labels', 'labels', DeckLabelMessages::errorCardLabels());
			}
		}
		return array_values($out);
	}

	/**
	 * @param list<string|int> $requested Normalized titles and IDs.
	 * @param list<array{id: int, boardId: int, title: string, color: string}> $catalog Labels of the board.
	 * @return array{assign: list<array{id: int, boardId: int, title: string, color: string}>, create: list<array{title: string, color: string}>}
	 * @throws DeckRefusalException When an ID is not on the board or a title is ambiguous.
	 */
	public static function resolve(array $requested, array $catalog): array {
		$byId = array_column($catalog, null, 'id');
		$assign = [];
		$create = [];
		foreach ($requested as $item) {
			if (is_int($item)) {
				$assign[$item] = $byId[$item] ?? throw new DeckRefusalException(DeckLabelMessages::unknownLabelId($item));
				continue;
			}
			$matches = LabelInput::matches($catalog, $item);
			if (count($matches) > 1) {
				throw new DeckRefusalException(DeckLabelMessages::titleAmbiguous($item));
			}
			if ($matches !== []) {
				$assign[$matches[0]['id']] = $byId[$matches[0]['id']];
			} else {
				$create[] = ['title' => $item, 'color' => LabelInput::defaultColor($item)];
			}
		}
		ksort($assign);
		return ['assign' => array_values($assign), 'create' => $create];
	}

	/**
	 * @param array{assign: list<array>, create: list<array>} $plan Resolved labels.
	 * @return list<array<string, mixed>> What the confirmation shows: existing labels, then new ones marked `new`.
	 */
	public static function shown(array $plan): array {
		return [...array_map(static fn (array $label): array => ['id' => $label['id'], 'title' => $label['title'], 'color' => $label['color']], $plan['assign']),
			...array_map(static fn (array $label): array => $label + ['new' => true], $plan['create'])];
	}

	/** Static helper. */
	private function __construct() {
	}
}
