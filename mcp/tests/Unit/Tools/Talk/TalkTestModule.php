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
		$tools = [
			\OCA\Mcp\Modules\Talk\Tools\ListConversations::class => new \OCA\Mcp\Modules\Talk\Tools\ListConversations(new \OCA\Mcp\Modules\Talk\Reading\ListConversationsHandler($reader, $errors)),
			\OCA\Mcp\Modules\Talk\Tools\ReadMessages::class => new \OCA\Mcp\Modules\Talk\Tools\ReadMessages(new \OCA\Mcp\Modules\Talk\Reading\ReadMessagesHandler($reader, $arguments, $errors)),
			\OCA\Mcp\Modules\Talk\Tools\Reply::class => new \OCA\Mcp\Modules\Talk\Tools\Reply(new \OCA\Mcp\Modules\Talk\Replying\ReplyHandler($draft, $writer, $resolver, $errors), new \OCA\Mcp\Modules\Talk\Replying\ReplyPreview($draft, $preview, $errors)),
			\OCA\Mcp\Modules\Talk\Tools\AttachFile::class => new \OCA\Mcp\Modules\Talk\Tools\AttachFile(new \OCA\Mcp\Modules\Talk\Attaching\AttachFileHandler($arguments, $resolver, $sharer, $logger, $errors), new \OCA\Mcp\Modules\Talk\Attaching\AttachFilePreview($arguments, $resolver, $preview, $errors)),
			\OCA\Mcp\Modules\Talk\Tools\QuoteFile::class => new \OCA\Mcp\Modules\Talk\Tools\QuoteFile(new \OCA\Mcp\Modules\Talk\Quoting\QuoteFileHandler($arguments, $resolver, $writer, $errors), new \OCA\Mcp\Modules\Talk\Quoting\QuoteFilePreview($arguments, $resolver, $preview, $errors)),
			\OCA\Mcp\Modules\Talk\Tools\MessageUser::class => new \OCA\Mcp\Modules\Talk\Tools\MessageUser(new \OCA\Mcp\Modules\Talk\Messaging\MessageUserHandler($arguments, $userConversations, $writer, $errors), new \OCA\Mcp\Modules\Talk\Messaging\MessageUserPreview($arguments, $userConversations, $preview, $errors)),
			\OCA\Mcp\Modules\Talk\Tools\SendBatch::class => new \OCA\Mcp\Modules\Talk\Tools\SendBatch(new \OCA\Mcp\Modules\Talk\Batching\SendBatchHandler($arguments, $resolver, $writer, $logger, $errors), new \OCA\Mcp\Modules\Talk\Batching\SendBatchPreview($arguments, $resolver, $preview, $errors)),
			\OCA\Mcp\Modules\Talk\Tools\CreateGroup::class => new \OCA\Mcp\Modules\Talk\Tools\CreateGroup(new \OCA\Mcp\Modules\Talk\Groups\CreateGroupHandler($arguments, $groups, $errors), new \OCA\Mcp\Modules\Talk\Groups\CreateGroupPreview($arguments, $userConversations, $preview, $errors)),
			\OCA\Mcp\Modules\Talk\Tools\ListUnread::class => new \OCA\Mcp\Modules\Talk\Tools\ListUnread(new \OCA\Mcp\Modules\Talk\Reading\ListUnreadHandler(self::unread($talkServices), $errors)),
		];
		return new TalkModule(new class($tools) implements \Psr\Container\ContainerInterface {
			/** @param array<class-string, \OCA\Mcp\Mcp\Tools\Tool> $tools prebuilt real tools with original test collaborators */
			public function __construct(private array $tools) {
			}
			/**
			 * @param string $id requested tool class
			 * @return mixed existing real tool, preserving the PSR container signature
			 * @throws \LogicException when a test asks for an unbuilt service
			 */
			public function get(string $id): mixed {
				return $this->tools[$id] ?? throw new \LogicException('Missing Talk test service: ' . $id);
			}
			/**
			 * @param string $id requested tool class
			 * @return bool whether that real tool was built for this test
			 */
			public function has(string $id): bool {
				return isset($this->tools[$id]);
			}
		});
	}

	/**
	 * The unread listing with the real lobby and name view and a signed cursor.
	 *
	 * The cursor secret and the display-name lookup are test doubles. The view itself is the production class:
	 * a room double that answers getDisplayName fails the test, because that method writes.
	 */
	private static function unread(TalkServices $talkServices): \OCA\Mcp\Modules\Talk\Reading\UnreadConversations {
		$doubles = new class('talk-unread-cursor') extends \PHPUnit\Framework\TestCase {
			public function testHolder(): void {
			}

			public function config(): \OCP\IConfig {
				$config = $this->createMock(\OCP\IConfig::class);
				$config->method('getSystemValueString')->willReturn('talk-unread-test-secret');
				return $config;
			}

			public function users(): \OCP\IUserManager {
				$users = $this->createMock(\OCP\IUserManager::class);
				$users->method('getDisplayName')->willReturnArgument(0);
				return $users;
			}
		};
		return new \OCA\Mcp\Modules\Talk\Reading\UnreadConversations(
			$talkServices,
			new \OCA\Mcp\Modules\Talk\Integration\UnreadRoomView($doubles->users(), 1),
			new \OCA\Mcp\Modules\Talk\Integration\UnreadCounter($doubles->users()),
			new \OCA\Mcp\Modules\Talk\Reading\UnreadCursor(new \OCA\Mcp\Mcp\Confirmation\PlanState($doubles->config())),
		);
	}
}
