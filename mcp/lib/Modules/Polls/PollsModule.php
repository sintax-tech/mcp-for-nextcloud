<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls;

use InvalidArgumentException;
use OCA\Mcp\Modules\Polls\Creation\CreatePlan;
use OCA\Mcp\Modules\Polls\Creation\CreatePollHandler;
use OCA\Mcp\Modules\Polls\Presentation\CreateDefinition;
use OCA\Mcp\Modules\Polls\Presentation\CreateMessages;
use OCA\Mcp\Modules\Polls\Presentation\PollsContentDefinitions;
use OCA\Mcp\Modules\Polls\Presentation\PollsContentMessages;
use OCA\Mcp\Modules\Polls\Presentation\PollsMessages;
use OCA\Mcp\Modules\Polls\Reading\ListLinksHandler;
use OCA\Mcp\Modules\Polls\Reading\ListPollsHandler;
use OCA\Mcp\Modules\Polls\Reading\ListVotesHandler;
use OCA\Mcp\Modules\Polls\Reading\ReadPollHandler;
use OCA\Mcp\Modules\Polls\Reading\ReadResultsHandler;
use OCA\Mcp\Mcp\{Tools\PreviewsWrites,Tools\RendersPlans};
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;

/** Registers native read handlers; protected votes/results and existing links have independent grants. */
final class PollsModule implements ToolModule, ToolGuideNotes, PreviewsWrites, RendersPlans {
	/**
	 * Receives one handler per tool.
	 * @param ListPollsHandler $list discovery
	 * @param ReadPollHandler $read structure
	 * @param ListVotesHandler $votes authorized votes
	 * @param ReadResultsHandler $results authorized native aggregates
	 * @param ListLinksHandler $links existing editor-authorized URLs
	 * @param CreatePollHandler $create private confirmed creation
	 * @return void
	 */
	public function __construct(private ListPollsHandler $list, private ReadPollHandler $read,
		private ListVotesHandler $votes, private ReadResultsHandler $results, private ListLinksHandler $links, private CreatePollHandler $create) {
	}

	/**
	 * Describes the native/output limits honestly.
	 * @return list<string> guide notes
	 */
	public function guideNotes(): array {
		return [PollsMessages::NOTICE, PollsMessages::READ, PollsContentMessages::COST, PollsContentMessages::LINKS, CreateMessages::TOOL, CreateMessages::NOTE];
	}

	/**
	 * Describes the read tools and closed argument schemas.
	 * @return list<array<string,mixed>> tool definitions
	 */
	public function definitions(): array {
		$page = static fn (string $limit, string $cursor, int $default): array => [
			$limit => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => $default],
			$cursor => ['type' => 'string', 'maxLength' => 512],
		];
		return [
			$this->definition(ListPollsHandler::TOOL, PollsMessages::LIST, $page('limit', 'cursor', 25)),
			$this->definition(ReadPollHandler::TOOL, PollsMessages::READ,
				['poll_id' => ['type' => 'integer', 'minimum' => 1]] + $page('options_limit', 'options_cursor', 100), ['poll_id']),
			...PollsContentDefinitions::all(), CreateDefinition::get(),
		];
	}

	/**
	 * Delegates each tool to its own handler.
	 * @param string $name tool
	 * @param array<string,mixed> $arguments validated input
	 * @param string $userId authenticated actor
	 * @return array<string,mixed> MCP response
	 * @throws InvalidArgumentException for an unknown tool
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return match ($name) {
			ListPollsHandler::TOOL => $this->list->handle($arguments, $userId),
			ReadPollHandler::TOOL => $this->read->handle($arguments, $userId),
			ListVotesHandler::TOOL => $this->votes->handle($arguments, $userId),
			ReadResultsHandler::TOOL => $this->results->handle($arguments, $userId),
			ListLinksHandler::TOOL => $this->links->handle($arguments, $userId),
			CreatePlan::TOOL => $this->create->handle($arguments, $userId),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}

	/**
	 * Delegates the private creation preview without effects.
	 * @param string $name write tool
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId actor
	 * @return array<string,mixed> plan
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return $name === CreatePlan::TOOL ? $this->create->preview($arguments,$userId) : throw new InvalidArgumentException('Unknown tool');
	}

	/**
	 * Delegates safe plan rendering to its focused renderer.
	 * @param string $tool tool name
	 * @param array<string,mixed> $plan structured preview
	 * @return string|null body or generic fallback for a non-writing tool
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return $tool === CreatePlan::TOOL ? CreatePlan::render($plan) : null;
	}

	/**
	 * Registers a read-only app-gated tool.
	 * @param string $name tool name
	 * @param string $description tool description
	 * @param array<string,mixed> $properties schema properties
	 * @param list<string> $required required properties
	 * @return array<string,mixed> definition
	 */
	private function definition(string $name, string $description, array $properties, array $required = []): array {
		return ['name' => $name, 'description' => $description, 'module' => 'polls', 'operation' => 'read', 'app' => 'polls',
			'inputSchema' => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false] + ($required === [] ? [] : ['required' => $required])];
	}
}
