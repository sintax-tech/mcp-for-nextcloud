<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\Tools\CheckAvailability;
use OCA\Mcp\Modules\Calendar\Tools\CreateEvent;
use OCA\Mcp\Modules\Calendar\Tools\DeleteEvent;
use OCA\Mcp\Modules\Calendar\Tools\ListCalendars;
use OCA\Mcp\Modules\Calendar\Tools\ListEvents;
use OCA\Mcp\Modules\Calendar\Tools\MoveEvent;
use OCA\Mcp\Modules\Calendar\Tools\SuggestTimes;
use OCA\Mcp\Modules\Calendar\Tools\TransferEvent;
use OCA\Mcp\Modules\Calendar\Tools\UpdateEvent;

/** The Calendar tools in tools/list order. A new tool goes at the end. */
final class CalendarToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> tool classes, in tools/list order */
	public const TOOLS = [
		ListCalendars::class,
		ListEvents::class,
		CreateEvent::class,
		UpdateEvent::class,
		MoveEvent::class,
		DeleteEvent::class,
		TransferEvent::class,
		CheckAvailability::class,
		SuggestTimes::class,
	];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
