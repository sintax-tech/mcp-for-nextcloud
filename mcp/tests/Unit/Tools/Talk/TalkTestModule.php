<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\Modules\Talk\{TalkModule, TalkArguments, Integration\TalkServices, Reading\ConversationReader, Locating\ConversationResolver, Writing\ConversationWriter, Attachments\FileSharer, Writing\WritePreview, Locating\UserConversationResolver, Groups\GroupCreator, ReferenceLinker, Failures\TalkErrors, Replying\ReplyDraft};
use Psr\Log\LoggerInterface;

/** Builds Talk with the exact collaborators supplied by its pre-migration tests. */
final class TalkTestModule {
	/**
	 * @param TalkServices $talkServices original test collaborator, preserved without substitution
	 * @param ConversationReader $reader original test collaborator, preserved without substitution
	 * @param ConversationResolver $resolver original test collaborator, preserved without substitution
	 * @param ConversationWriter $writer original test collaborator, preserved without substitution
	 * @param FileSharer $sharer original test collaborator, preserved without substitution
	 * @param WritePreview $preview original test collaborator, preserved without substitution
	 * @param UserConversationResolver $userConversations original test collaborator, preserved without substitution
	 * @param GroupCreator $groups original test collaborator, preserved without substitution
	 * @param ReferenceLinker $references original test collaborator, preserved without substitution
	 * @param LoggerInterface $logger original test collaborator, preserved without substitution
	 * @return TalkModule module composed from the original test collaborators
	 */
	public static function build(
		TalkServices $talkServices,
		ConversationReader $reader,
		ConversationResolver $resolver,
		ConversationWriter $writer,
		FileSharer $sharer,
		WritePreview $preview,
		UserConversationResolver $userConversations,
		GroupCreator $groups,
		ReferenceLinker $references,
		LoggerInterface $logger,
	): TalkModule {
		$arguments = new TalkArguments();
		$errors = new TalkErrors($logger);
		$draft = new ReplyDraft($arguments, $resolver, $references);
		return new TalkModule(
			$talkServices,
			$reader,
			$resolver,
			$writer,
			$sharer,
			$preview,
			$userConversations,
			$groups,
			$references,
			$logger,
			$arguments,
			new \OCA\Mcp\Modules\Talk\Replying\ReplyHandler($draft, $writer, $errors),
			new \OCA\Mcp\Modules\Talk\Replying\ReplyPreview($draft, $preview, $errors),
			new \OCA\Mcp\Modules\Talk\Attaching\AttachFileHandler($arguments, $resolver, $sharer, $logger, $errors),
			new \OCA\Mcp\Modules\Talk\Attaching\AttachFilePreview($arguments, $resolver, $preview, $errors),
			new \OCA\Mcp\Modules\Talk\Quoting\QuoteFileHandler($arguments, $resolver, $writer, $errors),
			new \OCA\Mcp\Modules\Talk\Quoting\QuoteFilePreview($arguments, $resolver, $preview, $errors),
			new \OCA\Mcp\Modules\Talk\Messaging\MessageUserHandler($arguments, $userConversations, $writer, $errors),
			new \OCA\Mcp\Modules\Talk\Messaging\MessageUserPreview($arguments, $userConversations, $preview, $errors),
			new \OCA\Mcp\Modules\Talk\Batching\SendBatchHandler($arguments, $resolver, $writer, $logger, $errors),
			new \OCA\Mcp\Modules\Talk\Batching\SendBatchPreview($arguments, $resolver, $preview, $errors),
			new \OCA\Mcp\Modules\Talk\Groups\CreateGroupHandler($arguments, $groups, $errors),
			new \OCA\Mcp\Modules\Talk\Groups\CreateGroupPreview($arguments, $userConversations, $preview, $errors),
			new \OCA\Mcp\Modules\Talk\Reading\ListConversationsHandler($reader, $errors),
			new \OCA\Mcp\Modules\Talk\Reading\ReadMessagesHandler($reader, $arguments, $errors),
		);
	}
}
