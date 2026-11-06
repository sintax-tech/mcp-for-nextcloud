<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Closed schemas of the ten independently granted mutation tools. */
final class PollsWriteSchema {
	/** Exact tool order and literal grant operations; options.remove is not parsed. */
	public const OPERATIONS = [
		'polls_update_poll' => 'configure', 'polls_set_poll_state' => 'configure',
		'polls_add_option' => 'options', 'polls_update_option' => 'options', 'polls_reorder_options' => 'options',
		'polls_remove_option' => 'options.remove', 'polls_restore_option' => 'options.remove',
		'polls_add_share' => 'share', 'polls_update_share' => 'share', 'polls_invite_share' => 'invite',
	];
	/** Boolean MCP configuration fields; adapters normalize native 0/1 explicitly. */
	public const BOOLEANS = ['allowComment', 'allowMaybe', 'autoReminder', 'hideBookedUp', 'useNo', 'forceConfidentialComments', 'allowDownload'];

	/** @param string $tool Exact published name. @return ToolDefinition The new tool only. */
	public static function definition(string $tool): ToolDefinition {
		return PollsSchema::definition(name: $tool, operation: self::OPERATIONS[$tool],
			description: 'Manage an existing poll with native edit permission, a complete plan and explicit confirmation. Share mutations never return credential links. Readback reports observed effects; invitation markers do not prove email delivery.',
			inputSchema: self::schema($tool), destructiveHint: $tool !== 'polls_add_option',
			requires: ['polls.native_mutation_api']);
	}

	/** @param string $tool Exact published name. @return array<string,mixed> Closed schema before registry confirmation injection. */
	public static function schema(string $tool): array {
		$id = PollsSchema::pollId();
		$properties = ['poll_id' => $id];
		$required = ['poll_id'];
		$more = match ($tool) {
			'polls_update_poll' => ['changes' => self::object(self::configuration()) + ['minProperties' => 1]],
			'polls_set_poll_state' => ['state' => self::enum(['closed', 'reopened', 'archived', 'unarchived'])],
			'polls_add_option' => ['option' => self::option()],
			'polls_update_option' => ['option_id' => $id, 'option' => self::option()],
			'polls_reorder_options' => ['option_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 200, 'uniqueItems' => true, 'items' => $id]],
			'polls_remove_option', 'polls_restore_option' => ['option_id' => $id],
			'polls_add_share' => ['type' => self::enum(['user', 'admin', 'group', 'email', 'contact', 'public'])],
			'polls_update_share' => ['share_id' => $id, 'action' => self::enum(['lock', 'unlock', 'label', 'set_user', 'set_admin', 'remove', 'restore'])],
			'polls_invite_share' => ['share_id' => $id],
		};
		$properties += $more;
		$required = [...$required, ...array_keys($more)];
		if ($tool === 'polls_add_share') {
			$properties['recipient'] = self::object(['user_id' => self::text(1, 255), 'group_id' => self::text(1, 255),
				'email_address' => self::text(1, 254), 'display_name' => self::text(0, 255), 'contact_id' => self::text(1, 512)])
				+ ['description' => 'Exactly user_id for user/admin, group_id for group, email_address and optional display_name for email, or contact_id for contact. Forbidden for public. No group expansion.'];
		}
		if ($tool === 'polls_update_share') { $properties['value'] = self::text(0, 255); }
		$properties['plan_state'] = PlanState::property();
		return self::object($properties, $required);
	}

	/** @return array<string,array<string,mixed>> Only the approved configuration fields. */
	public static function configuration(): array {
		$p = ['title' => self::text(1, 128), 'description' => self::text(0, 16384),
			'expire' => self::integer(0, 253402214400), 'proposalsExpire' => self::integer(0, 253402214400),
			'allowProposals' => self::enum(['allow', 'disallow', 'review']),
			'showResults' => self::enum(['always', 'closed', 'never']),
			'forcedDisplayMode' => self::enum(['user-pref', 'table-view', 'list-view']),
			'maxVotesPerOption' => self::integer(0, 2147483647), 'maxVotesPerUser' => self::integer(0, 2147483647),
			'timezoneName' => self::text(1, 128)];
		foreach (self::BOOLEANS as $key) { $p[$key] = ['type' => 'boolean']; }
		foreach (['allowDownload', 'timezoneName'] as $key) {
			$p[$key]['description'] = 'Supported only by Polls 9.x; unavailable on Polls 8.6.x.';
		}
		return $p;
	}
	/** @return array<string,mixed> Conditional text/date shape, completed by PollWriteRequest. */
	private static function option(): array {
		return self::object(['text' => self::text(1, 1024), 'timestamp' => self::integer(1, 253402214400), 'duration' => self::integer(0, 2678400)])
			+ ['description' => 'For a native text poll: only nonblank text, excluding the native empty value "0". For a date poll: UTC timestamp and optional duration in seconds (default 0); their sum must not exceed 253402300799. The branches cannot be mixed.'];
	}
	/** @param array<string,mixed> $properties Properties. @param list<string> $required Required keys. @return array<string,mixed> Closed object. */
	private static function object(array $properties, array $required = []): array {
		return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
	}
	/** @param int $min Minimum. @param int $max Maximum. @return array<string,mixed> Literal string. */
	private static function text(int $min, int $max): array { return ['type' => 'string', 'minLength' => $min, 'maxLength' => $max]; }
	/** @param int $min Minimum. @param int $max Maximum. @return array<string,mixed> Strict integer. */
	private static function integer(int $min, int $max): array { return ['type' => 'integer', 'minimum' => $min, 'maximum' => $max]; }
	/** @param list<string> $values Allowed strings. @return array<string,mixed> Enum. */
	private static function enum(array $values): array { return ['type' => 'string', 'enum' => $values]; }
}
