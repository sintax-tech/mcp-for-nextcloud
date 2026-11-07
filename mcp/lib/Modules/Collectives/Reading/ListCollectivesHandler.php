<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;

/** Backs `collectives_list`: the collectives the user is a member of, sliced by cursor. */
class ListCollectivesHandler {
	/** Tool name, part of the cursor signature. */
	private const TOOL = 'collectives_list';

	/**
	 * @param CollectivesGateway $gateway native Collectives
	 * @param PageCursor $cursors listing cursors
	 */
	public function __construct(
		private CollectivesGateway $gateway,
		private PageCursor $cursors,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_list arguments
	 * @return array{collectives:list<array<string, mixed>>, next_cursor:?string} one slice of the user's collectives
	 * @throws ToolFailure when the call is not admitted or the cursor is not valid
	 */
	public function handle(string $uid, array $arguments): array {
		$slice = $this->cursors->slice($arguments, $uid, self::TOOL);
		$collectives = $this->gateway->collectives($uid);
		usort($collectives, static fn (array $a, array $b): int => [strcasecmp($a['name'], $b['name']), $a['id']] <=> [0, $b['id']]);
		$page = $this->cursors->page($collectives, $slice, $arguments, $uid, self::TOOL);
		return ['collectives' => $page['items'], 'next_cursor' => $page['next_cursor']];
	}
}
