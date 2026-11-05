<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Modules\Mail\MailLinks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The Mail links come from Nextcloud's URL generator with the Mail app's own routes and the ids the Mail front end
 * reads: the folder id for a folder, and for a message its own folder and its own database id (never the thread root,
 * which the front end does not read); a message the Mail app has not threaded has no link.
 */
final class MailLinksTest extends TestCase {
	/**
	 * A folder link is the mailbox route with the folder id, absolute.
	 *
	 * @return void
	 */
	public function testAFolderLinkUsesTheMailboxRoute(): void {
		[$links, $calls] = $this->links();
		$this->assertSame('ABS:mail.page.mailbox:{"id":7}', $links->mailbox(7));
		$this->assertSame([['mail.page.mailbox', ['id' => 7]]], $calls->getArrayCopy());
	}

	/**
	 * A message link is the thread route with the message's own folder and database id, not its thread root.
	 *
	 * @return void
	 */
	public function testAMessageLinkUsesItsOwnFolderAndId(): void {
		[$links] = $this->links();
		$this->assertSame('ABS:mail.page.thread:{"mailboxId":5,"id":51}', $links->message(self::message(51, 5, '<root@example.com>')));
	}

	/**
	 * A message without a thread root, which the Mail front end cannot open, gets no link at all.
	 *
	 * @return void
	 */
	public function testAnUnthreadedMessageHasNoLink(): void {
		[$links, $calls] = $this->links();
		$this->assertNull($links->message(self::message(52, 5, null)));
		$this->assertNull($links->message(self::message(53, 5, '')));
		$this->assertSame([], $calls->getArrayCopy(), 'no route is even asked for');
	}

	/**
	 * The links over a URL generator that records the routes asked for.
	 *
	 * @return array{MailLinks, \ArrayObject<int, array{string, array<string, int>}>} the links and the recorded calls
	 */
	private function links(): array {
		$calls = new \ArrayObject();
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static function (string $route, array $parameters) use ($calls): string {
			$calls[] = [$route, $parameters];
			return 'ABS:' . $route . ':' . json_encode($parameters);
		});
		return [new MailLinks($urls), $calls];
	}

	/**
	 * A cached message with its folder and thread root.
	 *
	 * @param int $id database id
	 * @param int $mailboxId folder id
	 * @param string|null $root thread root id
	 * @return object the message
	 */
	private static function message(int $id, int $mailboxId, ?string $root): object {
		return new RecordingDouble('Message', [
			'getId' => static fn (): int => $id,
			'getMailboxId' => static fn (): int => $mailboxId,
			'getThreadRootId' => static fn (): ?string => $root,
		], new \ArrayObject());
	}
}
