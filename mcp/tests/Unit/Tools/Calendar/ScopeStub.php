<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\ScopeReader;
use OCA\Mcp\Modules\Calendar\Locating\CalendarAccess;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;

/**
 * The resource scope a calendar or task tool reads, held in memory: unrestricted unless a module is restricted, and
 * unreadable when $unavailable is set. Every question is recorded, so a test can tell what the tool asked.
 */
final class ScopeStub implements ScopeReader {
	/** @var array<string, list<string>> restricted modules and their items */
	public array $restricted = [];
	public bool $unavailable = false;
	/** @var list<array{string, string, string}> uid, module and resource id of every question */
	public array $asked = [];

	/** @param list<string> $items calendar ids the module is restricted to; [] restricts it to nothing */
	public function restrict(string $module, array $items): self {
		$this->restricted[$module] = $items;
		return $this;
	}

	public function inScope(string $userId, string $module, string $resourceId): bool {
		$this->asked[] = [$userId, $module, $resourceId];
		if ($this->unavailable) {
			throw new PolicyUnavailable('Resource scope unavailable');
		}
		return !isset($this->restricted[$module]) || in_array($resourceId, $this->restricted[$module], true);
	}

	public function assertReadable(string $userId, string $module): void {
		if ($this->unavailable) {
			throw new PolicyUnavailable('Resource scope unavailable');
		}
	}

	/** The tools' calendar access over a store, narrowed by $scope (unrestricted by default). */
	public static function access(CalendarStore $store, ?self $scope = null): ScopedCalendarAccess {
		return new ScopedCalendarAccess(new CalendarAccess($store), $scope ?? new self());
	}
}
