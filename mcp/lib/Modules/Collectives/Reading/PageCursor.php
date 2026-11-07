<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Reading;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCP\App\IAppManager;

/**
 * The opaque cursor of the Collectives listings: an offset signed for one user, one tool, one query and the installed
 * Collectives version, so a cursor never continues another listing, another account or a listing an update reordered.
 * Signed with the same server secret as plan states.
 */
class PageCursor {
	/**
	 * @param PlanState $states the instance's HMAC
	 * @param IAppManager $apps the installed Collectives version
	 */
	public function __construct(private PlanState $states, private IAppManager $apps) {
	}

	/**
	 * @param array<string, mixed> $arguments validated listing arguments
	 * @param string $uid authenticated actor
	 * @param string $tool tool name
	 * @return array{offset:int, limit:int} where the slice starts and how long it is
	 * @throws ToolFailure when the cursor was not given by this listing
	 */
	public function slice(array $arguments, string $uid, string $tool): array {
		$limit = (int)($arguments['limit'] ?? CollectivesSchema::DEFAULT_LIMIT);
		$cursor = $arguments['cursor'] ?? null;
		if ($cursor === null) {
			return ['offset' => 0, 'limit' => $limit];
		}
		$parts = explode('.', (string)$cursor, 2);
		$offset = ctype_digit($parts[0]) ? (int)$parts[0] : -1;
		if (count($parts) !== 2 || $offset < 1 || !hash_equals($this->sign($offset, $arguments, $uid, $tool), $parts[1])) {
			throw new ToolFailure(CollectivesMessages::invalidCursor());
		}
		return ['offset' => $offset, 'limit' => $limit];
	}

	/**
	 * @param list<mixed> $items the whole listing
	 * @param array{offset:int, limit:int} $slice what {@see self::slice()} returned
	 * @param array<string, mixed> $arguments validated listing arguments
	 * @param string $uid authenticated actor
	 * @param string $tool tool name
	 * @return array{items:list<mixed>, next_cursor:?string} the slice and the cursor of the next one
	 */
	public function page(array $items, array $slice, array $arguments, string $uid, string $tool): array {
		$next = $slice['offset'] + $slice['limit'];
		return [
			'items' => array_slice($items, $slice['offset'], $slice['limit']),
			'next_cursor' => $next < count($items) ? $next . '.' . $this->sign($next, $arguments, $uid, $tool) : null,
		];
	}

	/**
	 * @param int $offset where the next slice starts
	 * @param array<string, mixed> $arguments validated listing arguments
	 * @param string $uid authenticated actor
	 * @param string $tool tool name
	 * @return string the signature of that offset for this listing
	 */
	private function sign(int $offset, array $arguments, string $uid, string $tool): string {
		unset($arguments['cursor'], $arguments['limit']);
		ksort($arguments);
		return $this->states->of($tool . '#cursor', $uid, ['offset' => $offset, 'arguments' => $arguments,
			'version' => (string)$this->apps->getAppVersion('collectives')]);
	}
}
