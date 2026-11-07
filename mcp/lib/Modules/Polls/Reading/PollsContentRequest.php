<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Reading;

/** Closed read input for votes, aggregates and existing links. */
final class PollsContentRequest {
	/**
	 * Validates strictly, including when a handler is called without the registry.
	 * @param array<string,mixed> $arguments raw arguments
	 * @param bool $votes whether option_id is accepted
	 * @return array{id:int,limit:int,cursor:?string,option:?int} request
	 * @throws \InvalidArgumentException for invalid input
	 */
	public static function of(array $arguments, bool $votes): array {
		$allowed = $votes ? ['poll_id', 'limit', 'cursor', 'option_id'] : ['poll_id', 'limit', 'cursor'];
		$id = $arguments['poll_id'] ?? null;
		$limit = $arguments['limit'] ?? 50;
		$cursor = $arguments['cursor'] ?? null;
		$option = $arguments['option_id'] ?? null;
		if (array_diff(array_keys($arguments), $allowed) !== [] || !is_int($id) || $id < 1
			|| !is_int($limit) || $limit < 1 || $limit > 100 || (array_key_exists('limit', $arguments) && $arguments['limit'] === null)
			|| (array_key_exists('cursor', $arguments) && (!is_string($cursor) || $cursor === '' || strlen($cursor) > 512))
			|| (array_key_exists('option_id', $arguments) && (!is_int($option) || $option < 1))) {
			throw new \InvalidArgumentException('Invalid Polls arguments');
		}
		return ['id' => $id, 'limit' => $limit, 'cursor' => $cursor, 'option' => $option];
	}
}
