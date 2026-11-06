<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Replying;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;

/** The existing talk_reply preview without publication. */
final class ReplyPreview {
	/**
	 * @param ReplyDraft $draft shared final reply resolution
	 * @param WritePreview $preview existing side-effect-free draft
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private ReplyDraft $draft,
		private WritePreview $preview,
		private TalkErrors $errors,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated MCP arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged confirmation plan
	 * @throws InvalidArgumentException for invalid arguments
	 * @throws PolicyUnavailable when policy scope is unreadable
	 * @throws ToolFailure when the same call would be refused
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->errors->preview('talk_reply', function () use ($arguments, $userId): array {
			$resolved = $this->draft->resolve($userId, $arguments);
			$plan = $this->preview->reply(
				$resolved['conversation'],
				$userId,
				$resolved['message'],
				$resolved['replyTo'],
			);

			return $resolved['item'] === null ? $plan : $plan + ['reference' => $resolved['item']];
		});
	}
}
