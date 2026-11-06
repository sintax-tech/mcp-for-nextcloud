<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

/** Labels use board READ and a bounded native page; an empty result is a success, not a failure. */
final class ListLabelsHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	public const TOOL = 'deck_list_labels';
	public const DEFAULT_LIMIT = 50;

	/**
	 * @param array $arguments validated tool arguments
	 * @param string $userId authenticated caller UID
	 * @return array unchanged result of this operation
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$limit = (int)($arguments['limit'] ?? self::DEFAULT_LIMIT);
			$offset = (int)($arguments['offset'] ?? 0);
			$labels = $this->gateway->listLabels($userId, (int)$arguments['boardId'], $limit, $offset);
			return [
				'labels' => array_map(static fn ($label): array => [
					'id' => (int)$label->getId(), 'boardId' => (int)$label->getBoardId(),
					'title' => $label->getTitle(), 'color' => $label->getColor(),
				], $labels),
				'limit' => $limit,
				'offset' => $offset,
				'total' => count($labels) < $limit && ($offset === 0 || $labels !== []) ? $offset + count($labels) : null,
			];
		});
	}

	/**
	 * @return string unchanged result of this operation
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
