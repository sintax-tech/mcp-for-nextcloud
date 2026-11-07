<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Groups;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use OCA\Mcp\Modules\Talk\TalkArguments;

/** The existing talk_create_group effect and result. */
final class CreateGroupHandler {
	/**
	 * @param TalkArguments $arguments unchanged group argument checks
	 * @param GroupCreator $groups existing native room creation and invitations
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private TalkArguments $arguments,
		private GroupCreator $groups,
		private TalkErrors $errors,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated MCP arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged MCP result envelope
	 * @throws InvalidArgumentException for invalid arguments
	 * @throws PolicyUnavailable when policy scope is unreadable
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->errors->call('talk_create_group', function () use ($arguments, $userId): array {
			$name = GroupCreator::normalizeName((string)($arguments['name'] ?? ''));
			$participants = $this->arguments->participantIds($arguments);


			return ToolResult::success($this->groups->create($userId, $name, $participants));
		});
	}
}
