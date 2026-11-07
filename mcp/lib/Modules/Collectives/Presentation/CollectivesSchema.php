<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Presentation;

use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesCapabilities;

/**
 * The input schemas of the Collectives tools: the definition every tool shares (module and app `collectives`, the
 * `collectives.native` capability, no additional property) and the properties several tools repeat.
 */
final class CollectivesSchema {
	/** Longest title the tools accept, in characters. */
	public const MAX_TITLE = 200;
	/** Largest page of a listing. */
	public const MAX_LIMIT = 100;
	/** Page of a listing when the caller gives no limit. */
	public const DEFAULT_LIMIT = 50;

	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param string $name tool name
	 * @param string $operation grant operation
	 * @param string $description English description for the model
	 * @param array<string, array<string, mixed>> $properties input properties, in published order
	 * @param list<string> $required required properties
	 * @param list<string> $requires capabilities besides `collectives.native`
	 * @param list<string>|null $grantAnyOf the grants that admit the tool instead of its operation, null for the operation
	 * @param bool $destructiveHint whether the tool may take something away beyond what its operation says
	 * @return ToolDefinition the definition of one Collectives tool
	 */
	public static function definition(string $name, string $operation, string $description, array $properties, array $required = [],
		array $requires = [], ?array $grantAnyOf = null, bool $destructiveHint = false): ToolDefinition {
		$schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];
		return new ToolDefinition(
			name: $name,
			module: 'collectives',
			operation: $operation,
			description: $description,
			inputSchema: $required === [] ? $schema : $schema + ['required' => $required],
			app: 'collectives',
			grantAnyOf: $grantAnyOf,
			destructiveHint: $destructiveHint,
			requires: [CollectivesCapabilities::KEY, ...$requires],
		);
	}

	/** @return array<string, mixed> the collective id property */
	public static function collectiveId(): array {
		return ['type' => 'integer', 'minimum' => 1, 'description' => CollectivesMessages::PARAM_COLLECTIVE_ID];
	}

	/**
	 * @param string $description what the page id means for this tool
	 * @return array<string, mixed> a page id property
	 */
	public static function pageId(string $description = CollectivesMessages::PARAM_PAGE_ID): array {
		return ['type' => 'integer', 'minimum' => 1, 'description' => $description];
	}

	/** @return array<string, mixed> the title property */
	public static function title(string $description = CollectivesMessages::PARAM_TITLE): array {
		return ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TITLE, 'description' => $description];
	}

	/** @return array<string, array<string, mixed>> the cursor and limit properties of a listing */
	public static function paging(): array {
		return [
			'cursor' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256, 'description' => CollectivesMessages::PARAM_CURSOR],
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT, 'description' => CollectivesMessages::PARAM_LIMIT],
		];
	}

	/** @return array<string, mixed> the member id property of the member tools */
	public static function memberId(): array {
		return ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => MembershipMessages::PARAM_MEMBER_ID];
	}

	/** @return array<string, mixed> the optimistic-concurrency ETag property */
	public static function etag(): array {
		return ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => CollectivesMessages::PARAM_ETAG];
	}
}
