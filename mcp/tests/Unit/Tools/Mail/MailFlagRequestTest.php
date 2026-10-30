<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mail\Events\MessageFlaggedEvent;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Mail\Flag\MailFlagEvent;
use OCA\Mcp\Tools\Mail\Flag\MailFlagRequest;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../stubs/OCA/Mail/Events/MessageFlaggedEvent.php';

/**
 * A flag request is a wanted state of seen and flagged only, whose changes are the flags not already so; the Mail flag
 * event is built in the shape of the installed Mail release, with the UID up to 5.10.x and the message from 5.11.0 on.
 */
final class MailFlagRequestTest extends TestCase {
	/**
	 * Only seen and flagged are read, as booleans; other keys are never flags.
	 *
	 * @return void
	 */
	public function testTheRequestIsAWantedStateOfTwoFlags(): void {
		$this->assertSame(['seen' => true], MailFlagRequest::from(['message_id' => 1, 'seen' => true, 'deleted' => true])->wanted);
		$this->assertSame(['seen' => false, 'flagged' => true], MailFlagRequest::from(['flagged' => true, 'seen' => false])->wanted);
		$this->assertSame(['seen' => '\\seen', 'flagged' => '\\flagged'], MailFlagRequest::FLAGS);
	}

	/**
	 * A call that asks for no flag is an argument error.
	 *
	 * @return void
	 */
	public function testAskingForNothingIsRefused(): void {
		$this->expectException(ArgumentValidationException::class);
		MailFlagRequest::from(['message_id' => 1]);
	}

	/**
	 * The changes are the asked flags not already in that state, never a toggle.
	 *
	 * @return void
	 */
	public function testChangesAreTheFlagsNotAlreadySo(): void {
		$request = MailFlagRequest::from(['seen' => true, 'flagged' => false]);
		$this->assertSame(['seen' => true], $request->changes(['seen' => false, 'flagged' => false]));
		$this->assertSame([], $request->changes(['seen' => true, 'flagged' => false]));
		$this->assertSame(['seen' => true, 'flagged' => false], $request->changes(['seen' => null, 'flagged' => true]));
	}

	/**
	 * The event takes the UID when its third parameter is an int, the message otherwise, and is dispatched typed.
	 *
	 * @return void
	 */
	public function testTheEventFollowsTheInstalledShape(): void {
		$this->assertTrue(MailFlagEvent::takesUid(UidFlaggedEvent::class));
		$this->assertFalse(MailFlagEvent::takesUid(MessageFlaggedEvent::class));
		$dispatched = [];
		$events = $this->createStub(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(static function (object $event) use (&$dispatched): void {
			$dispatched[] = $event;
		});
		$message = new RecordingDouble('Message', ['getUid' => static fn (): int => 42], new \ArrayObject());
		$account = new \stdClass();
		$mailbox = new \stdClass();
		(new MailFlagEvent($events))->dispatch(UidFlaggedEvent::class, $account, $mailbox, $message, 'seen', true);
		(new MailFlagEvent($events))->dispatch(MessageFlaggedEvent::class, $account, $mailbox, $message, 'flagged', false);
		$this->assertSame([42, 'seen', true], [$dispatched[0]->uid, $dispatched[0]->flag, $dispatched[0]->set]);
		$this->assertSame([$message, 'flagged', false], [$dispatched[1]->message, $dispatched[1]->flag, $dispatched[1]->set]);
		$this->assertSame($mailbox, $dispatched[1]->mailbox);
	}
}
