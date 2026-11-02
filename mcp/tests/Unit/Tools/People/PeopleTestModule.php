<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\People;

use OCA\Mcp\Modules\People\PeopleModule;
use OCA\Mcp\Modules\People\SearchPeopleHandler;
use OCA\Mcp\Modules\People\Tools\SearchPeople;
use OCP\Collaboration\Collaborators\ISearch;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** Builds the People catalog module with the search doubles its unit tests used to give the legacy constructor. */
final class PeopleTestModule {
	public static function build(ISearch $search, LoggerInterface $logger): PeopleModule {
		return new PeopleModule(self::container([
			SearchPeople::class => new SearchPeople(new SearchPeopleHandler($search, $logger)),
		]));
	}

	/**
	 * @param array<string, object> $services instances by id
	 */
	private static function container(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				return $this->services[$id] ?? throw new \LogicException("no test service $id");
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}
}
