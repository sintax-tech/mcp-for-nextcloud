<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

/** Registration data for three read tools with independent protected-read grants. */
final class PollsContentDefinitions {
	/**
	 * Builds closed schemas and exact grantAnyOf admission without changing the read operation/hints.
	 * @return list<array<string,mixed>> definitions
	 */
	public static function all(): array {
		$out = [];
		foreach (['votes' => ['polls_list_votes', 'results', PollsContentMessages::VOTES],
			'results' => ['polls_read_results', 'results', PollsContentMessages::RESULTS],
			'links' => ['polls_list_links', 'links', PollsContentMessages::LINKS]] as $kind => [$tool, $grant, $description]) {
			$properties = ['poll_id' => ['type' => 'integer', 'minimum' => 1],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
				'cursor' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512]];
			if ($kind === 'votes') {
				$properties['option_id'] = ['type' => 'integer', 'minimum' => 1];
			}
			$out[] = ['name' => $tool, 'description' => $description, 'module' => 'polls', 'operation' => 'read',
				'app' => 'polls', 'grantAnyOf' => [$grant], 'inputSchema' => ['type' => 'object', 'properties' => $properties,
					'required' => ['poll_id'], 'additionalProperties' => false]];
		}
		return $out;
	}
}
