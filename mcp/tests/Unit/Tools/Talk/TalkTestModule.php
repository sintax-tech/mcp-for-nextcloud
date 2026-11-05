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
		);
	}
}
