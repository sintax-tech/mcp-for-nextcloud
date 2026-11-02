<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Observed answers of the six Polls tools at the move commit, before the schema, the plan renderer and the catalog
 * were split. The same inputs on this tree have to reproduce polls-characterization.json.
 */
final class PollsCharacterizationTest extends TestCase {
	private const FIXTURE = __DIR__ . '/polls-characterization.json';

	public function testTheSplitPreservesTheCharacterizedAnswers(): void {
		$report = ['reads' => $this->reads(), 'create' => $this->create()];
		$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RE19_FREEZE') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}
		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}

	/** @return array<string, mixed> */
	private function reads(): array {
		$rig = new PollsTestRig($this);
		$rig->policy->setGrant('alice', 'polls', 'results', true);
		$rig->policy->setGrant('alice', 'polls', 'links', true);
		$rig->poll(1, 'alice', ['title' => "Lunch \u{202E}<b>", 'description' => "a\x01b <3", 'results' => true]);
		$rig->poll(2, 'bob', ['access' => 'open', 'created' => 200, 'results' => false]);
		$rig->poll(3, 'bob', ['access' => 'private']);
		$rig->option(10, 1, ['votes' => ['yes' => 2, 'no' => 1, 'maybe' => 0, 'count' => 3]]);
		$rig->option(11, 1, ['text' => "Dinner \u{200F}", 'votes' => ['yes' => 0, 'no' => 0, 'maybe' => 1, 'count' => 1]]);
		$rig->option(12, 1, ['order' => 0, 'votes' => ['yes' => 0, 'no' => 0, 'maybe' => 0, 'count' => 0]]);
		NativeProtectedScene::$votes[1] = [
			['id' => 1, 'pollId' => 1, 'optionId' => 10, 'optionText' => 'a<b', 'answer' => 'yes', 'deleted' => 0, 'owner' => 'alice',
				'user' => ['id' => 'alice', 'type' => 'user', 'displayName' => 'Alice', 'email' => 'PRIVATE'], 'userId' => 'RAW'],
			['id' => 2, 'pollId' => 1, 'optionId' => 11, 'optionText' => 'Dinner', 'answer' => 'maybe', 'deleted' => 0, 'owner' => 'bob',
				'user' => ['id' => md5('bob'), 'type' => 'anonymous', 'displayName' => 'Pseudonym'], 'userId' => 'bob'],
		];
		NativeProtectedScene::$shares[1] = [
			['id' => 5, 'pollId' => 1, 'type' => 'public', 'url' => 'https://cloud.test/polls/s/TOKEN-5', 'locked' => 0, 'deleted' => 0],
			['id' => 4, 'pollId' => 1, 'type' => 'email', 'url' => 'https://cloud.test/polls/s/TOKEN-4', 'locked' => 1, 'deleted' => 0],
			['id' => 6, 'pollId' => 1, 'type' => 'circle', 'url' => 'https://cloud.test/polls/s/TOKEN-6', 'locked' => 0, 'deleted' => 0],
		];
		$out = ['definitions' => array_map(static function (array $definition): array {
			ksort($definition);
			return $definition;
		}, $rig->module->definitions()), 'guide' => $rig->module->guideNotes()];
		$first = $this->attempt(fn () => $rig->call('polls_list_polls', ['limit' => 1]));
		$out['list_first'] = $first;
		$out['list_next'] = $this->attempt(fn () => $rig->call('polls_list_polls', ['limit' => 1, 'cursor' => $first['nextCursor']]));
		$out['list_bad_cursor'] = $this->attempt(fn () => $rig->call('polls_list_polls', ['cursor' => 'v1.e30.x']));
		$out['list_extra'] = $this->attempt(fn () => $rig->call('polls_list_polls', ['nope' => 1]));
		$poll = $this->attempt(fn () => $rig->call('polls_read_poll', ['poll_id' => 1, 'options_limit' => 2]));
		$out['read_first'] = $poll;
		$out['read_next'] = $this->attempt(fn () => $rig->call('polls_read_poll', ['poll_id' => 1, 'options_limit' => 2, 'options_cursor' => $poll['nextCursor']]));
		$out['read_hidden'] = $this->attempt(fn () => $rig->call('polls_read_poll', ['poll_id' => 3]));
		$out['read_missing'] = $this->attempt(fn () => $rig->call('polls_read_poll', ['poll_id' => 99]));
		$out['votes'] = $this->attempt(fn () => $rig->call('polls_list_votes', ['poll_id' => 1]));
		$out['votes_option'] = $this->attempt(fn () => $rig->call('polls_list_votes', ['poll_id' => 1, 'option_id' => 11, 'limit' => 1]));
		$out['votes_own'] = $this->attempt(fn () => $rig->call('polls_list_votes', ['poll_id' => 2]));
		$out['results'] = $this->attempt(fn () => $rig->call('polls_read_results', ['poll_id' => 1, 'limit' => 2]));
		$out['results_hidden'] = $this->attempt(fn () => $rig->call('polls_read_results', ['poll_id' => 2]));
		$out['links'] = $this->attempt(fn () => $rig->call('polls_list_links', ['poll_id' => 1]));
		$out['links_not_editor'] = $this->attempt(fn () => $rig->call('polls_list_links', ['poll_id' => 2]));
		$out['links_option_refused'] = $this->attempt(fn () => $rig->call('polls_list_links', ['poll_id' => 1, 'option_id' => 1]));
		$out['unknown_tool'] = $this->attempt(fn () => $rig->module->call('polls_vote', [], 'alice'));
		$rig->policy->setGrant('alice', 'polls', 'results', false);
		$out['votes_without_grant'] = $this->attempt(fn () => $rig->call('polls_list_votes', ['poll_id' => 1]));
		$rig->enabled = false;
		$out['list_app_disabled'] = $this->attempt(fn () => $rig->call('polls_list_polls'));
		return $out;
	}

	/** @return array<string, mixed> */
	private function create(): array {
		$rig = new PollsCreateRig($this);
		$module = $rig->base->module;
		$args = ['type' => 'textPoll', 'title' => 'Private <3', 'options' => [['text' => '&amp;'], ['text' => 'a<b']]];
		$plan = $this->attempt(fn () => $module->preview('polls_create_poll', $args, 'alice'));
		$out = ['plan' => $plan, 'render' => $module->renderPlan('polls_create_poll', $plan), 'render_read' => $module->renderPlan('polls_read_poll', $plan)];
		$out['unconfirmed'] = $this->attempt(fn () => $this->decode($module->call('polls_create_poll', $args, 'alice')));
		$out['stale_plan'] = $this->attempt(fn () => $this->decode($module->call('polls_create_poll', $args + ['confirm' => true, 'plan_state' => str_repeat('0', 64)], 'alice')));
		$out['receipt'] = $this->attempt(fn () => $this->decode($module->call('polls_create_poll', $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice')));
		$dates = ['type' => 'datePoll', 'title' => 'When', 'options' => [['timestamp' => 1700000000, 'duration' => 3600], ['timestamp' => 1700003600]]];
		$datePlan = $this->attempt(fn () => $module->preview('polls_create_poll', $dates, 'alice'));
		$out['date_plan'] = $datePlan;
		$out['date_render'] = $module->renderPlan('polls_create_poll', $datePlan);
		$out['invalid'] = $this->attempt(fn () => $module->preview('polls_create_poll', ['type' => 'textPoll', 'title' => "\u{202E}x"], 'alice'));
		$out['duplicate'] = $this->attempt(fn () => $module->preview('polls_create_poll', ['type' => 'textPoll', 'title' => 'T', 'options' => [['text' => 'x'], ['text' => 'x']]], 'alice'));
		$rig->base->policy->setGrant('alice', 'polls', 'create', false);
		$out['without_grant'] = $this->attempt(fn () => $module->preview('polls_create_poll', $args, 'alice'));
		$out['effects'] = NativeCreateScene::$effects;
		return $out;
	}

	/**
	 * @param array<string, mixed> $result MCP envelope
	 * @return array<string, mixed>
	 */
	private function decode(array $result): array {
		return ['isError' => $result['isError'] ?? null, 'data' => json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)];
	}

	/**
	 * Runs one call; an exception is recorded by its family and message, since an unknown tool reaches a catalog
	 * module as the InvalidArgumentException subclass UnknownToolException with the same message.
	 *
	 * @param callable(): mixed $call the call
	 * @return mixed the answer, or the refusal
	 */
	private function attempt(callable $call): mixed {
		try {
			return $call();
		} catch (\Throwable $error) {
			$class = $error instanceof InvalidArgumentException && $error::class === 'OCA\Mcp\Mcp\Tools\UnknownToolException'
				? InvalidArgumentException::class : $error::class;
			return ['error' => $class, 'message' => $error->getMessage()];
		}
	}
}
