<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/**
 * Method names the Collectives member tools call on Teams (Circles) objects that other Nextcloud classes named in lib/
 * also declare. The calls are on the Teams manager and team only; their shapes are proven by TeamsSurface and by
 * CollectivesAdminContractTest against the pinned Circles 33-35.
 */
final class CollectivesApiUsage {
	/** @var array<string, string> class::method of the other class => what lib/ actually calls */
	public const NAME_CLASHES = [
		'OCA\\Polls\\Model\\UserBase::isConfig' => 'OCA\\Circles\\Model\\Circle::isConfig, in Modules/Collectives/Integration/TeamsGateway',
		'OCA\\Polls\\Model\\User\\Anon::isConfig' => 'OCA\\Circles\\Model\\Circle::isConfig, in Modules/Collectives/Integration/TeamsGateway',
		'OCA\\Talk\\Manager::isConfig' => 'OCA\\Circles\\Model\\Circle::isConfig, in Modules/Collectives/Integration/TeamsGateway',
		'OCA\\Talk\\Service\\RoomService::isConfig' => 'OCA\\Circles\\Model\\Circle::isConfig, in Modules/Collectives/Integration/TeamsGateway',
		'OCP\\IUserManager::getFederatedUser' => 'OCA\\Circles\\CirclesManager::getFederatedUser, in Modules/Collectives/Integration/TeamsGateway',
	];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
