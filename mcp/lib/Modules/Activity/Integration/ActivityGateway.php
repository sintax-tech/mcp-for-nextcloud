<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Integration;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;

/** Common native Data::get prefix, with real preferences and a fresh non-formatting collector per page. */
final class ActivityGateway {
	/** One indistinguishable refusal for absent, foreign, expired or restarted native cursors. */
	public const INVALID_CURSOR = 'Invalid Activity cursor.';
	/**
	 * @param ContainerInterface $container lazy native Data and UserSettings
	 * @param ActivitySurface $surface fail-closed collector contract
	 */
	public function __construct(private ContainerInterface $container, private ActivitySurface $surface) {}
	/**
	 * @param string $actor authenticated owner
	 * @param int $since native ID
	 * @param int $limit bounded native page
	 * @return array{data:array,has_more:bool} raw authorized rows
	 * @throws InvalidArgumentException indistinguishable invalid cursor
	 * @throws PolicyUnavailable incompatible native surface or page shape
	 */
	public function page(string $actor, int $since, int $limit): array {
		if (!$this->surface->supported()) throw new PolicyUnavailable('The Activity feed is unavailable.');
		$data = $this->container->get(ActivitySurface::DATA);
		if ($since > 0) {
			$cursor = $data->getById($since);
			if ($cursor === null || $cursor->getAffectedUser() !== $actor) throw new InvalidArgumentException(self::INVALID_CURSOR);
		}
		try {
			$page = $data->get(new ActivityRows(), $this->container->get(ActivitySurface::SETTINGS), $actor, $since, $limit, 'desc', 'all', '', 0, false);
		} catch (OutOfBoundsException) {
			throw new InvalidArgumentException(self::INVALID_CURSOR);
		}
		if (!isset($page['data'], $page['has_more']) || !is_array($page['data']) || !is_bool($page['has_more']) || count($page['data']) > $limit) throw new PolicyUnavailable('The Activity feed is unavailable.');
		// Data can restart from the top if retention removes the cursor between the two native reads.
		foreach ($page['data'] as $row) {
			if ($since > 0 && (int)($row['activity_id'] ?? 0) >= $since) throw new InvalidArgumentException(self::INVALID_CURSOR);
		}
		return ['data' => $page['data'], 'has_more' => $page['has_more']];
	}
}
