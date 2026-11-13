<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCA\Mcp\Modules\Routines\Presentation\MailCardMessages;

/**
 * routines_create_mail_card through the real registry: it exists only with its own grant, Mail, Deck and attachment
 * support; a call without confirm is the plan; a confirmed call whose state changed answers the new plan, written by
 * nothing.
 */
final class MailCardRegistryTest extends MailCardWorld {
	/** @return list<string> the routine tools alice sees now */
	private function listed(): array {
		return array_column($this->registry->list('alice'), 'name');
	}

	/** With every grant and app, the routine is listed as a destructive write. @return void */
	public function testTheRoutineIsListedWithItsGrantAndApps(): void {
		$tools = array_column($this->registry->list('alice'), null, 'name');

		self::assertArrayHasKey('routines_create_mail_card', $tools);
		self::assertTrue($tools['routines_create_mail_card']['annotations']['destructiveHint']);
	}

	/** @return array<string, array{\Closure(self): void}> what hides the routine from alice */
	public static function hidden(): array {
		return [
			'routines.mailtask denied' => [static function (self $test): void { $test->sharePolicy->setGrant('alice', 'routines', 'mailtask', false); }],
			'Deck off' => [static function (self $test): void { $test->enabled['deck'] = false; }],
			'Mail off' => [static function (self $test): void { $test->enabled['mail'] = false; }],
			'Guests without Deck' => [static function (self $test): void { $test->guestApps = ['mail']; }],
		];
	}

	/**
	 * The routine leaves tools/list and a direct call is an unknown tool, with nothing read or written.
	 *
	 * @param \Closure(self): void $hide what hides it
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('hidden')]
	public function testTheRoutineDisappearsWithoutItsGrantOrAnApp(\Closure $hide): void {
		$hide($this);

		self::assertNotContains('routines_create_mail_card', $this->listed());
		$this->expectException(UnknownToolException::class);
		try {
			$this->registry->call('routines_create_mail_card', self::arguments(['confirm' => true]), 'alice');
		} finally {
			$this->assertNothingWritten();
		}
	}

	/** A call without confirm answers the plan with its plan_state, and writes nothing. @return void */
	public function testACallWithoutConfirmIsThePlan(): void {
		$answer = $this->registry->call('routines_create_mail_card', self::arguments(), 'alice');

		self::assertTrue($answer['structuredContent']['requiresConfirmation']);
		self::assertSame(64, strlen($answer['structuredContent']['plan_state']));
		self::assertStringContainsString('Pedido 42', $answer['content'][0]['text']);
		$this->assertNothingWritten();
	}

	/** A confirmed call whose email changed since the plan answers the new plan through the registry, writing nothing. @return void */
	public function testAChangedPlanIsAnsweredAsTheNewPlan(): void {
		$state = $this->registry->call('routines_create_mail_card', self::arguments(), 'alice')['structuredContent']['plan_state'];
		$this->mail->withMessage(1, ['id' => 7, 'subject' => 'Pedido 43', 'size' => 2000, 'threadRootId' => 'root-7', 'body' => 'Outro.',
			'structure' => \OCA\Mcp\Tests\Unit\Tools\Mail\FakeMimePart::multipart('multipart/mixed', [new \OCA\Mcp\Tests\Unit\Tools\Mail\FakeMimePart('text/plain'),
				new \OCA\Mcp\Tests\Unit\Tools\Mail\FakeMimePart('application/pdf', 'pedido.pdf', 'attachment')]),
			'attachmentContents' => ['2' => self::BYTES], 'attachmentList' => [['id' => '2', 'fileName' => 'pedido.pdf', 'mime' => 'application/pdf', 'size' => 9]]]);

		$answer = $this->registry->call('routines_create_mail_card', self::arguments(['confirm' => true, 'plan_state' => $state]), 'alice');

		self::assertTrue($answer['structuredContent']['requiresConfirmation']);
		self::assertSame('Pedido 43', $answer['structuredContent']['email']['message']['subject']);
		self::assertContains(MailCardMessages::planChanged(), $answer['structuredContent']['notices']);
		$this->assertNothingWritten();
	}

	/** The confirmed call with the plan's plan_state saves the file and creates the card. @return void */
	public function testTheConfirmedCallRunsThroughTheRegistry(): void {
		$state = $this->registry->call('routines_create_mail_card', self::arguments(), 'alice')['structuredContent']['plan_state'];

		$answer = $this->registry->call('routines_create_mail_card', self::arguments(['confirm' => true, 'plan_state' => $state]), 'alice');

		self::assertFalse($answer['isError'] ?? false, json_encode($answer));
		self::assertSame('completed', json_decode($answer['content'][0]['text'], true)['status']);
		self::assertCount(1, $this->created);
		self::assertSame(self::BYTES, $this->tree->nodes[self::TARGET]['content']);
	}
}
