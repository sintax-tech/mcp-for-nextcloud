<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Integration;

use OCA\Activity\GroupHelper;

/** Fresh in-memory row collector. No parent constructor, parsing, grouping, previews or providers are ever called. */
final class ActivityRows extends GroupHelper {
	/** @var list<array<string,mixed>> unformatted rows, never returned directly to the client */
	private array $rows = [];
	/** No native dependencies or constructor effects. */
	public function __construct() {}
	/**
	 * @param array<string,mixed> $activity one native authorized row
	 * @return void collect without invoking addEvent
	 */
	public function addActivity(array $activity): void { $this->rows[] = $activity; }
	/** @return list<array<string,mixed>> bounded raw page for the guarded MCP projector */
	public function getActivities(): array { return $this->rows; }
}
