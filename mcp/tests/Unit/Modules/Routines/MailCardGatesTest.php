<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Routines\Presentation\MailCardMessages;
use OCA\Mcp\Modules\Routines\Presentation\MailCardSchema;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * routines_create_mail_card over the real ports: every grant in AND, the apps, Guests, the Files scope and the native
 * ACLs refuse it with nothing written; a confirmed card reads the email with PEEK and saves its exact bytes.
 */
final class MailCardGatesTest extends MailCardWorld {
	/** routines.mailtask starts denied, and is independent of the grants of the steps. @return void */
	public function testTheRoutineGrantIsDeniedByDefault(): void {
		self::assertFalse(GrantPolicy::defaultGranted('routines', 'mailtask'));
		self::assertSame(['mail.attachments'], MailCardSchema::definition()->requires);
		self::assertSame(['mail', 'deck'], MailCardSchema::definition()->apps);
	}

	/** @return array<string, array{string, string, string}> each grant of a step, and the original tool that names it */
	public static function stepGrants(): array {
		return [
			'mail.messages' => ['mail', 'messages', 'mail_read_message'],
			'mail.save' => ['mail', 'save', 'mail_save_attachment'],
			'deck.create' => ['deck', 'create', 'deck_create_card'],
		];
	}

	/**
	 * Without the grant of any one step, the plan is refused and the confirmed call writes nothing.
	 *
	 * @param string $module grant module of the step
	 * @param string $operation grant operation of the step
	 * @param string $tool the original tool the refusal names
	 * @return void
	 */
	#[DataProvider('stepGrants')]
	public function testEveryStepGrantIsRequired(string $module, string $operation, string $tool): void {
		$state = $this->cardPlan()['plan_state'];
		$this->tree->ops = [];
		$this->sharePolicy->setGrant('alice', $module, $operation, false);

		$result = $this->tool->execute(self::arguments() + ['confirm' => true, 'plan_state' => $state], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
		$receipt = json_decode($result['content'][0]['text'], true);
		self::assertTrue($result['isError'] ?? false);
		self::assertStringContainsString($tool, json_encode($receipt));
		$this->assertNothingWritten();
		$this->expectException(ToolFailure::class);
		$this->cardPlan();
	}

	/** @return array<string, array{\Closure(self): void}> an app off for alice, or not allowed by Guests */
	public static function closedApps(): array {
		return [
			'Deck off for the user' => [static function (self $test): void { $test->enabled['deck'] = false; }],
			'Mail off for the user' => [static function (self $test): void { $test->enabled['mail'] = false; }],
			'Guests without Deck' => [static function (self $test): void { $test->guestApps = ['mail', 'files']; }],
		];
	}

	/**
	 * An app the routine needs, off or out of Guests, refuses it before anything is read or written.
	 *
	 * @param \Closure(self): void $close the gate to close
	 * @return void
	 */
	#[DataProvider('closedApps')]
	public function testEveryAppAndGuestsAreRequired(\Closure $close): void {
		$close($this);

		$this->expectException(ToolFailure::class);
		try {
			$this->cardPlan();
		} finally {
			$this->assertNothingWritten();
		}
	}

	/** A folder outside alice's Files scope refuses the save, so no file and no card exist. @return void */
	public function testADestinationOutsideTheFilesScopeIsRefused(): void {
		$this->items = [(string)$this->tree->nodes['/alice/files/Outros']['id']];

		$this->expectException(ToolFailure::class);
		try {
			$this->cardPlan();
		} finally {
			$this->assertNothingWritten();
		}
	}

	/** A folder alice may not add to is refused by its ACL; a list she may not write is refused by the board ACL. @return void */
	public function testTheNativeAclsRefuseWithNothingWritten(): void {
		try {
			$this->cardPlan(['path' => '/Leitura/pedido.pdf']);
			self::fail('A read-only folder must refuse the plan');
		} catch (ToolFailure) {
		}
		$this->readers->refused = true;
		$plan = $this->cardPlan();
		self::assertSame(['readable' => false], $plan['board_readers'], 'a board whose readers cannot be read is never private');
		self::assertContains(MailCardMessages::sharedNeeded(), $plan['notices']);
		$this->assertNothingWritten();
	}

	/** An occupied name is refused before the download and keeps its bytes; no card is created. @return void */
	public function testAnOccupiedNameIsNeverOverwritten(): void {
		$this->tree->addFile(self::TARGET, 'old content', 'application/pdf');
		$this->tree->ops = [];

		$this->expectException(ToolFailure::class);
		try {
			$this->cardPlan();
		} finally {
			self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
			$this->assertNothingWritten();
		}
	}

	/** The email is read with PEEK and stays unread; the file holds the exact bytes and the card its hash-checked path. @return void */
	public function testTheConfirmedCardSavesTheExactBytesWithPeek(): void {
		[$receipt, $error] = $this->planAndConfirm(['excerpt_chars' => 5]);

		self::assertFalse($error);
		self::assertSame('completed', $receipt['status'], json_encode($receipt));
		self::assertSame(self::BYTES, $this->tree->nodes[self::TARGET]['content']);
		self::assertSame(hash('sha256', self::BYTES), $receipt['steps'][0]['result']['sha256']);
		self::assertSame([], array_values(array_filter($this->mail->imapCommands(), static fn (string $c): bool => str_starts_with($c, 'imap:store'))));
		self::assertSame(1, count(array_filter($this->mail->imapCommands(), static fn (string $c): bool => $c === 'imap:attachment:2')));
		[[$stack, $title, $description]] = $this->created;
		self::assertSame([10, 'Pedido 42'], [$stack, $title]);
		self::assertStringContainsString('https://cloud.example/f/' . $this->tree->nodes[self::TARGET]['id'], $description);
		self::assertStringContainsString("```\nSegue\n```", $description);
	}

	/** A private email going to alice's own board shared with a group is named in the plan and needs confirm_shared. @return void */
	public function testAPrivateEmailToASharedBoardNeedsConfirmShared(): void {
		$this->readers->acls = [new \OCA\Deck\Db\Acl(['type' => \OCA\Deck\Db\Acl::PERMISSION_TYPE_GROUP, 'participant' => 'vendas'])];

		$plan = $this->cardPlan();
		self::assertSame('vendas', $plan['board_readers']['entries'][0]['id']);
		self::assertContains(MailCardMessages::disclosure(), $plan['notices']);
		self::assertContains(MailCardMessages::groupsAreOpen(), $plan['notices']);

		[$receipt, $error] = $this->planAndConfirm();
		self::assertTrue($error);
		self::assertSame('refused', $receipt['status']);
		$this->assertNothingWritten();

		[$receipt] = $this->planAndConfirm(['confirm_shared' => true]);
		self::assertSame('completed', $receipt['status']);
	}

	/**
	 * Readers added to the board while the file is saved stop the card: the file stays, named, and the board is read
	 * again after the save, so a plan of a private board never publishes the email to a shared one.
	 *
	 * @return void
	 */
	public function testABoardSharedDuringTheSaveStopsTheCardAndKeepsTheFile(): void {
		$this->tree->afterWrite = function (): void {
			$this->readers->acls = [new \OCA\Deck\Db\Acl(['type' => \OCA\Deck\Db\Acl::PERMISSION_TYPE_GROUP, 'participant' => 'vendas'])];
		};

		[$receipt, $error] = $this->planAndConfirm();

		self::assertFalse($error, 'a receipt after a write is never an error that invites a retry');
		self::assertSame(['stopped', 'card'], [$receipt['status'], $receipt['stopped_at']]);
		self::assertSame([WorkflowStep::APPLIED, WorkflowStep::FAILED], array_column($receipt['steps'], 'status'));
		self::assertSame(MailCardMessages::boardChanged(), $receipt['steps'][1]['message']);
		self::assertSame(['path' => '/Documentos/pedido.pdf', 'fileId' => $this->tree->nodes[self::TARGET]['id']], $receipt['steps'][0]['ids']);
		self::assertSame(self::BYTES, $this->tree->nodes[self::TARGET]['content']);
		self::assertSame([], $this->created);
		self::assertSame([10, 10, 10], $this->readers->checked, 'plan, preflight and again after the save');
	}

	/** @return array<string, array{bool}> a policy failure that says it may have applied, and one that says nothing */
	public static function readBackPolicies(): array {
		return ['marked uncertain' => [true], 'default flag' => [false]];
	}

	/**
	 * A policy the read-back of the saved file cannot read leaves the file uncertain with its path and id and starts no
	 * card, whatever the exception says of itself: the receipt reaches the user instead of a 503, and nothing is deleted.
	 *
	 * @param bool $uncertain the flag the policy failure carries
	 * @return void
	 */
	#[DataProvider('readBackPolicies')]
	public function testAnUncertainPolicyAfterTheSinkAnswersAReceiptWithoutACard(bool $uncertain): void {
		$this->tree->afterWrite = function () use ($uncertain): void {
			$this->tree->beforeGet = function () use ($uncertain): void {
				$this->tree->beforeGet = null;
				throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('readback unavailable', $uncertain);
			};
		};

		[$receipt, $error] = $this->planAndConfirm();

		self::assertFalse($error);
		self::assertSame(['stopped', 'file'], [$receipt['status'], $receipt['stopped_at']]);
		self::assertSame([WorkflowStep::UNCERTAIN, 'not_started'], array_column($receipt['steps'], 'status'));
		self::assertSame(['path' => '/Documentos/pedido.pdf', 'fileId' => $this->tree->nodes[self::TARGET]['id']], $receipt['steps'][0]['ids']);
		self::assertSame(self::BYTES, $this->tree->nodes[self::TARGET]['content']);
		self::assertSame([], $this->created);
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
	}

	/** A policy unreadable before any write still answers 503, as everywhere else, with nothing written. @return void */
	public function testAPolicyUnreadableBeforeTheWriteStaysA503(): void {
		$this->tree->beforeCreate = static fn () => throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('policy');

		$this->expectException(\OCA\Mcp\Access\Policy\PolicyUnavailable::class);
		try {
			$this->planAndConfirm();
		} finally {
			self::assertSame([], $this->created);
			self::assertArrayNotHasKey(self::TARGET, $this->tree->nodes);
		}
	}
}
