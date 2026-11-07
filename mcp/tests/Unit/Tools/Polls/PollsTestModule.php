<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Modules\Polls\Creation\CreatePollHandler;
use OCA\Mcp\Modules\Polls\Creation\CreatePollPreview;
use OCA\Mcp\Modules\Polls\PollsModule;
use OCA\Mcp\Modules\Polls\Reading\ListLinksHandler;
use OCA\Mcp\Modules\Polls\Reading\ListPollsHandler;
use OCA\Mcp\Modules\Polls\Reading\ListVotesHandler;
use OCA\Mcp\Modules\Polls\Reading\ReadPollHandler;
use OCA\Mcp\Modules\Polls\Reading\ReadResultsHandler;
use OCA\Mcp\Modules\Polls\Tools\CreatePoll;
use OCA\Mcp\Modules\Polls\Tools\ListLinks;
use OCA\Mcp\Modules\Polls\Tools\ListPolls;
use OCA\Mcp\Modules\Polls\Tools\ListVotes;
use OCA\Mcp\Modules\Polls\Tools\ReadPoll;
use OCA\Mcp\Modules\Polls\Tools\ReadResults;
use Psr\Container\ContainerInterface;

/**
 * Builds the Polls catalog module from the handlers its tests used to hand the legacy constructor, wiring each tool
 * the way the app container autowires it. The tool instances stay replaceable until the module first resolves them.
 */
final class PollsTestModule {
	/**
	 * @param \ArrayObject<string, object> $tools receives the tool instances by class
	 * @param array{ListVotesHandler, ReadResultsHandler, ListLinksHandler} $content the three protected reads
	 */
	public static function build(\ArrayObject $tools, ListPollsHandler $list, ReadPollHandler $read, array $content,
		CreatePollHandler $create, CreatePollPreview $plan): PollsModule {
		[$votes, $results, $links] = $content;
		$tools[ListPolls::class] = new ListPolls($list);
		$tools[ReadPoll::class] = new ReadPoll($read);
		$tools[ListVotes::class] = new ListVotes($votes);
		$tools[ReadResults::class] = new ReadResults($results);
		$tools[ListLinks::class] = new ListLinks($links);
		$tools[CreatePoll::class] = new CreatePoll($create, $plan);
		return new PollsModule(new class($tools) implements ContainerInterface {
			public function __construct(private \ArrayObject $tools) {
			}

			public function get(string $id): mixed {
				return $this->tools[$id] ?? throw new \LogicException("no test service $id");
			}

			public function has(string $id): bool {
				return isset($this->tools[$id]);
			}
		});
	}
}
