<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Polls\Creation\CreatePlan;

/**
 * The input schemas the Polls tools publish: the definition every tool of the module shares (module `polls`, app
 * `polls`, no additional property) and the properties several tools repeat. Each family keeps the key order it was
 * published with, since that order reaches the client in tools/list.
 */
final class PollsSchema {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param string $name tool name
	 * @param string $operation grant operation
	 * @param string $description English description for the model
	 * @param array<string, mixed> $inputSchema the closed input schema, in published key order
	 * @param list<string>|null $grantAnyOf grants that admit the tool instead of the operation itself
	 * @return ToolDefinition the definition of one Polls tool
	 */
	public static function definition(string $name, string $operation, string $description, array $inputSchema, ?array $grantAnyOf = null): ToolDefinition {
		return new ToolDefinition(
			name: $name,
			module: 'polls',
			operation: $operation,
			description: $description,
			inputSchema: $inputSchema,
			app: 'polls',
			grantAnyOf: $grantAnyOf,
		);
	}

	/**
	 * @param string $name tool name
	 * @param string $description English description for the model
	 * @param array<string, array<string, mixed>> $properties input properties, in published order
	 * @param list<string> $required required properties
	 * @return ToolDefinition a discovery or structure read, admitted by the read grant
	 */
	public static function read(string $name, string $description, array $properties, array $required = []): ToolDefinition {
		return self::definition($name, 'read', $description,
			['type' => 'object', 'properties' => $properties, 'additionalProperties' => false] + ($required === [] ? [] : ['required' => $required]));
	}

	/**
	 * @param string $limit name of the page size property
	 * @param string $cursor name of the cursor property
	 * @param int $default page size when the property is omitted
	 * @return array<string, array<string, mixed>> the paging properties of a discovery or structure read
	 */
	public static function page(string $limit, string $cursor, int $default): array {
		return [
			$limit => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => $default],
			$cursor => ['type' => 'string', 'maxLength' => 512],
		];
	}

	/**
	 * @param string $name tool name
	 * @param string $grant the protected-read grant that alone admits the tool
	 * @param string $description English description for the model
	 * @param bool $votes whether the read also filters by option_id
	 * @return ToolDefinition a protected read of votes, results or links of one poll
	 */
	public static function content(string $name, string $grant, string $description, bool $votes): ToolDefinition {
		$properties = ['poll_id' => ['type' => 'integer', 'minimum' => 1],
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
			'cursor' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512]];
		if ($votes) {
			$properties['option_id'] = ['type' => 'integer', 'minimum' => 1];
		}
		return self::definition($name, 'read', $description,
			['type' => 'object', 'properties' => $properties, 'required' => ['poll_id'], 'additionalProperties' => false], [$grant]);
	}

	/**
	 * Item-specific alternatives are validated by CreateRequest even on direct calls.
	 *
	 * @return ToolDefinition the private creation, admitted by the create grant
	 */
	public static function create(): ToolDefinition {
		return self::definition(CreatePlan::TOOL, 'create', CreateMessages::TOOL,
			['type' => 'object', 'required' => ['type', 'title'], 'additionalProperties' => false, 'properties' => [
				'type' => ['type' => 'string', 'enum' => ['textPoll', 'datePoll']],
				'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
				'options' => ['type' => 'array', 'maxItems' => 100, 'default' => [], 'description' => '0 to 100 initial options. textPoll items: only text (1..1024 characters). datePoll items: timestamp (positive UTC seconds) and optional duration (0..2678400 seconds). Items and their fields are strictly validated.',
					'items' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
						'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024],
						'timestamp' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 253402214400],
						'duration' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2678400]]]],
				PlanState::ARGUMENT => PlanState::property()]]);
	}
}
