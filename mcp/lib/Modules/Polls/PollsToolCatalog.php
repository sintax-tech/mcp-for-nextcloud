<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls;

use OCA\Mcp\Modules\Polls\Tools\CreatePoll;
use OCA\Mcp\Modules\Polls\Tools\ListLinks;
use OCA\Mcp\Modules\Polls\Tools\ListPolls;
use OCA\Mcp\Modules\Polls\Tools\ListVotes;
use OCA\Mcp\Modules\Polls\Tools\ReadPoll;
use OCA\Mcp\Modules\Polls\Tools\ReadResults;

/** The Polls tools in tools/list order. A new tool goes at the end. */
final class PollsToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> tool classes, in tools/list order */
	public const TOOLS = [
		ListPolls::class,
		ReadPoll::class,
		ListVotes::class,
		ReadResults::class,
		ListLinks::class,
		CreatePoll::class,
	];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
