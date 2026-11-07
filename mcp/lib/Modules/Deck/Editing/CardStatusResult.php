<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Editing;

use OCA\Mcp\Modules\Deck\Presentation\DeckStatusMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckWriteMessages;
use Throwable;

/** Classifies freshly observed outcomes, including the second native update of done on Deck1.19. */
final class CardStatusResult {
	/**
	 * @param array<string,mixed> $before Authorized pre-write state.
	 * @param array<string,mixed> $after Fresh authorized read-back.
	 * @param string $field Status field whose effect was called explicitly by its handler.
	 * @param bool $value Intended state.
	 * @param Throwable|null $error Native failure, including a post-write listener exception.
	 * @return array<string,mixed> Outcome metadata, never pre-write card data.
	 * @throws Throwable Original failure only when a reliable read proves no state change.
	 */
	public static function of(array $before, array $after, string $field, bool $value, ?Throwable $error): array {
		$card = $after['card'];
		$old = $before['card'];
		$matches = ($field === 'done' ? $card['done'] !== null : $card['archived']) === $value;
		$expectedStack = $old['stackId'];
		if ($field === 'done' && $value && $before['autoMove']['destination'] !== null) {
			$expectedStack = $before['autoMove']['destination']['id'];
		}
		$otherPreserved = $card['id'] === $old['id'] && $card['boardId'] === $old['boardId']
			&& $card['title'] === $old['title'] && $card['deletedAt'] === 0
			&& ($field === 'done' ? $card['archived'] === $old['archived'] : $card['done'] === $old['done']);
		if ($matches && $otherPreserved && $card['stackId'] === $expectedStack) {
			return ['confirmed' => true, 'changed' => true]
				+ ($error === null ? [] : ['warnings' => [DeckWriteMessages::writtenThenFailed()]]);
		}
		if ($error !== null && $before === $after) {
			throw $error;
		}
		if ($field === 'done' && $value && $matches && $otherPreserved && $card['stackId'] === $old['stackId'] && $expectedStack !== $old['stackId']) {
			return ['confirmed' => false, 'partial' => true, 'warnings' => [DeckStatusMessages::partialMove()]];
		}
		return ['confirmed' => false, 'message' => DeckWriteMessages::writeUnconfirmed('deck_read_card')];
	}
}
