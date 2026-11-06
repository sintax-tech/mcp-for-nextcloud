<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\Writing\PollWritePlan;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

/** One data provider and construction harness for all mutation guards. */
final class PollsMutationCases {
	/** @return iterable<string,array{string,array<string,mixed>}> All ten public tools. */
	public static function all(): iterable {
		yield 'configuration' => ['polls_update_poll', ['poll_id' => 1, 'changes' => ['title' => 'New']]];
		yield 'state' => ['polls_set_poll_state', ['poll_id' => 1, 'state' => 'archived']];
		yield 'add option' => ['polls_add_option', ['poll_id' => 1, 'option' => ['text' => 'New']]];
		yield 'update option' => ['polls_update_option', ['poll_id' => 1, 'option_id' => 10, 'option' => ['text' => 'New']]];
		yield 'reorder' => ['polls_reorder_options', ['poll_id' => 1, 'option_ids' => [11, 10]]];
		yield 'remove' => ['polls_remove_option', ['poll_id' => 1, 'option_id' => 10]];
		yield 'restore' => ['polls_restore_option', ['poll_id' => 1, 'option_id' => 10]];
		yield 'share' => ['polls_add_share', ['poll_id' => 1, 'type' => 'user', 'recipient' => ['user_id' => 'dave']]];
		yield 'update share' => ['polls_update_share', ['poll_id' => 1, 'share_id' => 20, 'action' => 'set_admin']];
		yield 'invite' => ['polls_invite_share', ['poll_id' => 1, 'share_id' => 20]];
	}
	/** @param TestCase $test Mock owner. @param PollsMutationScene $scene Native port scene. @return PollsMutationFlows Separate preview and effect production workers. */
	public static function flow(TestCase $test, PollsMutationScene $scene): PollsMutationFlows {
		return new PollsMutationFlows($scene, new PollWritePlan(new PlanState((new InMemoryConfig())->mock($test))));
	}
	/** @param TestCase $test Mock owner. @return array<string,array<string,mixed>> Actual preview plans for the global readability contract. */
	public static function plans(TestCase $test): array {
		$out = [];
		foreach (self::all() as [$tool, $args]) { $out[$tool] = self::flow($test, new PollsMutationScene($tool))->run($tool, $args, 'alice', true); }
		return $out;
	}
	/** @param list<array<string,mixed>> $tools Full current catalog. @return list<array<string,mixed>> The six unchanged legacy definitions. */
	public static function legacy(array $tools): array {
		return array_values(array_filter($tools, static fn (array $row): bool => !isset(\OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema::OPERATIONS[$row['name']])));
	}
}
