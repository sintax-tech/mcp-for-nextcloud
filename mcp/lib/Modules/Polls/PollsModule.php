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
use OCA\Mcp\Modules\Polls\Presentation\PollsContentMessages;
use OCA\Mcp\Modules\Polls\Presentation\PollsGuideNotes;
use OCA\Mcp\Modules\Polls\Presentation\PollsMessages;
use OCA\Mcp\Modules\Polls\Presentation\PollsPlanRenderer;
use OCA\Mcp\Modules\Polls\Presentation\PollsSchema;
use OCA\Mcp\Modules\Polls\Reading\ListLinksHandler;
use OCA\Mcp\Modules\Polls\Reading\ListPollsHandler;
use OCA\Mcp\Modules\Polls\Reading\ListVotesHandler;
use OCA\Mcp\Modules\Polls\Reading\ReadPollHandler;
use OCA\Mcp\Modules\Polls\Reading\ReadResultsHandler;
use OCA\Mcp\Mcp\{Tools\PreviewsWrites,Tools\RendersPlans};
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
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
		return PollsGuideNotes::notes();
	}

	/**
	 * Describes the read tools and closed argument schemas.
	 * @return list<array<string,mixed>> tool definitions
	 */
	public function definitions(): array {
		return array_map(static fn (ToolDefinition $definition): array => $definition->toArray(), [
			PollsSchema::read(ListPollsHandler::TOOL, PollsMessages::LIST, PollsSchema::page('limit', 'cursor', 25)),
			PollsSchema::read(ReadPollHandler::TOOL, PollsMessages::READ,
				['poll_id' => ['type' => 'integer', 'minimum' => 1]] + PollsSchema::page('options_limit', 'options_cursor', 100), ['poll_id']),
			PollsSchema::content(ListVotesHandler::TOOL, 'results', PollsContentMessages::VOTES, true),
			PollsSchema::content(ReadResultsHandler::TOOL, 'results', PollsContentMessages::RESULTS, false),
			PollsSchema::content(ListLinksHandler::TOOL, 'links', PollsContentMessages::LINKS, false),
			PollsSchema::create(),
		]);
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
		return (new PollsPlanRenderer())->render($tool, $plan);
	}

}
