<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Integration;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use Psr\Container\ContainerInterface;

/** Common native Data::get prefix, with real preferences and a fresh non-formatting collector per page. */
final class ActivityGateway {
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
	 */
	public function page(string $actor, int $since, int $limit): array {
		if (!$this->surface->supported()) throw new PolicyUnavailable('The Activity feed is unavailable.');
		$page = $this->container->get(ActivitySurface::DATA)->get(new ActivityRows(), $this->container->get(ActivitySurface::SETTINGS), $actor, $since, $limit, 'desc', 'all', '', 0, false);
		if (!isset($page['data'], $page['has_more']) || !is_array($page['data']) || !is_bool($page['has_more']) || count($page['data']) > $limit) throw new PolicyUnavailable('The Activity feed is unavailable.');
		return ['data' => $page['data'], 'has_more' => $page['has_more']];
	}
}
