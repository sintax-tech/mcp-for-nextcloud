<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tagging;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Files\Presentation\TaggingMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/**
 * Picks the one tag a call names, by ID or by exact name, among the tags the native catalog lets the actor see,
 * without the MCP exclusion tags ({@see FileTagList}).
 *
 * A tag outside that list, whether it does not exist, is invisible to the actor or excludes files from MCP, and a
 * name shared by several tags all get the same not-found refusal as a missing file: no answer tells them apart.
 */
final class TagChoice {
	/** Static choice only. */
	private function __construct() {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @return array{0:?string,1:?string} the tag ID or the tag name the call gives, exactly one of them
	 * @throws ArgumentValidationException when both or neither of tag_id and tag are given
	 */
	public static function requested(array $arguments): array {
		$id = isset($arguments['tag_id']) ? (string)$arguments['tag_id'] : null;
		$name = isset($arguments['tag']) ? (string)$arguments['tag'] : null;
		if (($id === null) === ($name === null)) {
			throw new ArgumentValidationException('Invalid arguments', '', TaggingMessages::oneOfTagIdOrName());
		}
		return [$id, $name];
	}

	/**
	 * @param array{0:?string,1:?string} $requested the tag ID or the tag name, from {@see self::requested()}
	 * @param list<array{id:string,name:string,user_visible:bool,user_assignable:bool,can_assign:bool}> $catalog tags the actor may see, exclusion tags removed
	 * @return array{id:string,name:string} the tag
	 * @throws ToolFailure the not-found refusal for any tag outside the catalog, or an ambiguous name
	 */
	public static function pick(array $requested, array $catalog): array {
		[$id, $name] = $requested;
		$matches = array_values(array_filter($catalog, static fn (array $tag): bool => $id !== null ? $tag['id'] === $id : $tag['name'] === $name));
		if (count($matches) !== 1) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		return ['id' => $matches[0]['id'], 'name' => $matches[0]['name']];
	}
}
