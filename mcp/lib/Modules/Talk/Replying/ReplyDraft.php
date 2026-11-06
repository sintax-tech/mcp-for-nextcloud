<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Replying;

use OCA\Mcp\Modules\Talk\Locating\Conversation;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\ReferenceLinker;
use OCA\Mcp\Modules\Talk\TalkArguments;

/** Resolves the final reply text identically for preview and confirmed publication. */
final class ReplyDraft {
	/**
	 * @param TalkArguments $arguments existing argument shape checks
	 * @param ConversationResolver $resolver existing writable-conversation resolver
	 * @param ReferenceLinker $references existing reference authorization and linking
	 */
	public function __construct(
		private TalkArguments $arguments,
		private ConversationResolver $resolver,
		private ReferenceLinker $references,
	) {
	}

	/**
	 * Everything a reply needs, resolved the same way on the plan and on the confirmed call.
	 *
	 * The reference is resolved again on the confirmed call instead of trusted from the plan: access is checked
	 * at the moment of sending, and a title that changed since the plan changes the text, so the plan the user
	 * saw is no longer the text that would go out.
	 *
	 * @param string $userId Authenticated user
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return array{conversation:Conversation, message:string, replyTo:int|null, item:array<string, mixed>|null}
	 */
	public function resolve(string $userId, array $arguments): array {
		$message = $this->arguments->message($arguments);
		$reference = $this->arguments->reference($arguments);
		$conversation = $this->resolver->resolveForWriting($userId, $this->arguments->token($arguments));

		$item = $reference === null ? null : $this->references->resolve($userId, $reference);
		if ($item !== null) {
			$message = ReferenceLinker::append($message, $item);
		}

		return [
			'conversation' => $conversation,
			'message' => $message,
			'replyTo' => $this->arguments->replyTo($arguments),
			'item' => $item,
		];
	}
}
