<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailSaveMessages;
use OCA\Mcp\Platform\Workflows\WorkflowStep;

/**
 * The attachment step of a mail card goes through the very plan and effect of mail_save_attachment, behind the
 * registry's own ToolAccess, and only ever creates a new file: an occupied name is refused and keeps its bytes.
 */
final class MailAttachmentSaveStepsTest extends MailAttachmentSaveStepsTestCase {
	/** The plan is the metadata-only plan of mail_save_attachment for a new file. @return void */
	public function testThePlanIsTheOneOfMailSaveAttachmentAndWritesNothing(): void {
		$plan = $this->steps->planSave('alice', 7, '2', '/Documentos/saved.pdf', false);

		self::assertSame('mail_save_attachment', $plan['action']);
		self::assertFalse($plan['replace']);
		self::assertSame('/Documentos/saved.pdf', $plan['path']);
		self::assertSame([], $this->tree->ops);
		self::assertNotContains('imap:attachment:2', $this->mail->imapCommands());
	}

	/** The confirmed step saves the bytes once and answers the read-back file with its private link. @return void */
	public function testTheConfirmedStepSavesANewFileAndReadsItBack(): void {
		$step = $this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false);

		self::assertSame(WorkflowStep::APPLIED, $step->status, (string)$step->message);
		self::assertSame(self::BYTES, $this->tree->nodes[self::TARGET]['content']);
		self::assertSame(['path' => '/Documentos/saved.pdf', 'fileId' => $this->tree->nodes[self::TARGET]['id']], $step->ids);
		self::assertSame('https://cloud.example/files.View.showFile/' . $step->ids['fileId'], $step->result['url']);
		self::assertSame(hash('sha256', self::BYTES), $step->result['sha256']);
		self::assertSame(MailSaveMessages::saved(), $step->result['message']);
	}

	/** An occupied name is refused before the download and the existing file keeps its bytes: there is no replacement. @return void */
	public function testAnOccupiedNameIsRefusedAndNeverOverwritten(): void {
		$this->tree->addFile(self::TARGET, 'old content', 'application/pdf');
		$this->tree->ops = [];

		self::assertSame(MailSaveMessages::occupied(), $this->failedWithoutWriting($this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false)));
		self::assertSame('old content', $this->tree->nodes[self::TARGET]['content']);
		$this->expectException(ToolFailure::class);
		$this->steps->planSave('alice', 7, '2', '/Documentos/saved.pdf', false);
	}

	/** @return array<string, array{\Closure(self): void}> the registry gates of mail_save_attachment */
	public static function refusedByTheRegistry(): array {
		return [
			'mail.save not granted' => [static function (self $test): void { $test->sharePolicy->setGrant('alice', 'mail', 'save', false); }],
			'Mail app off for the actor' => [static function (self $test): void { $test->appEnabled = false; }],
		];
	}

	/**
	 * Without the grant or the app, neither the plan nor the step reaches Mail or Files.
	 *
	 * @param \Closure(self): void $deny the gate to close
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusedByTheRegistry')]
	public function testTheRegistryGateRefusesBothThePlanAndTheStep(\Closure $deny): void {
		$deny($this);

		self::assertStringContainsString('mail_save_attachment', $this->failedWithoutWriting($this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false)));
		self::assertSame([], $this->mail->imapCommands());
		$this->expectException(ToolFailure::class);
		$this->steps->planSave('alice', 7, '2', '/Documentos/saved.pdf', false);
	}

	/** A destination outside the actor's Files scope is refused; the selected folder still accepts. @return void */
	public function testADestinationOutsideTheFilesScopeIsRefused(): void {
		$this->items = [(string)$this->tree->nodes['/alice/files/Outros']['id']];

		$this->failedWithoutWriting($this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false));
		self::assertSame(WorkflowStep::APPLIED, $this->steps->save('alice', 7, '2', '/Outros/saved.pdf', false)->status);
	}

	/** A folder the native ACL does not let the actor add to is refused with nothing written. @return void */
	public function testAFolderWithoutCreatePermissionIsRefusedByItsAcl(): void {
		$this->failedWithoutWriting($this->steps->save('alice', 7, '2', '/Leitura/saved.pdf', false));
		self::assertArrayNotHasKey('/alice/files/Leitura/saved.pdf', $this->tree->nodes);
	}

	/** The arguments are checked against the published schema of mail_save_attachment before anything. @return void */
	public function testArgumentsAreCheckedAgainstThePublishedSchemaBeforeAnything(): void {
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->steps->save('alice', 0, '2', '/Documentos/saved.pdf', false);
		} finally {
			self::assertSame([], $this->mail->imapCommands());
			self::assertSame([], $this->tree->ops);
		}
	}

	/** A failure of the write itself is uncertain, keeps whatever was written and is never retried. @return void */
	public function testAFailureOfTheWriteItselfIsUncertainAndNothingIsDeleted(): void {
		$this->tree->writeFailure = new \RuntimeException('native sink failure');

		$step = $this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false);

		self::assertSame(WorkflowStep::UNCERTAIN, $step->status);
		self::assertSame('/Documentos/saved.pdf', $step->ids['path']);
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
		self::assertSame(1, count(array_filter($this->mail->imapCommands(), static fn (string $c): bool => $c === 'imap:attachment:2')));
	}

	/** @return array<string, array{\Closure(self): void, string}> failures before any write and at the sink, with the classification mail_save_attachment gives them */
	public static function classifiedLikeTheOriginalTool(): array {
		return [
			'occupied name, before any write' => [static function (self $test): void { $test->tree->addFile(self::TARGET, 'old content', 'application/pdf'); }, WorkflowStep::FAILED],
			'quota at the sink, after the file exists' => [static function (self $test): void { $test->tree->writeFailure = new \OCP\Files\NotEnoughSpaceException('quota'); }, WorkflowStep::UNCERTAIN],
			'lock at the sink, after the file exists' => [static function (self $test): void { $test->tree->writeFailure = new \OCP\Lock\LockedException('saved.pdf'); }, WorkflowStep::UNCERTAIN],
			'lock at the read-back, after the file exists' => [static function (self $test): void { $test->failReadBack(new \OCP\Lock\LockedException(self::TARGET)); }, WorkflowStep::UNCERTAIN],
			'quota at the read-back, after the file exists' => [static function (self $test): void { $test->failReadBack(new \OCP\Files\NotEnoughSpaceException(self::TARGET)); }, WorkflowStep::UNCERTAIN],
			'policy unreadable at the read-back, after the file exists' => [static function (self $test): void { $test->failReadBack(new \OCA\Mcp\Access\Policy\PolicyUnavailable('readback unavailable')); }, WorkflowStep::UNCERTAIN],
			'destination hidden at the read-back' => [static function (self $test): void { $test->tree->afterWrite = function () use ($test): void { $test->items = [(string)$test->tree->nodes['/alice/files/Outros']['id']]; }; }, WorkflowStep::FAILED],
			'newFile throws after the node exists' => [static function (self $test): void { $test->tree->afterCreate = function () use ($test): void { $test->tree->afterCreate = null; throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed'); }; }, WorkflowStep::UNCERTAIN],
			'storage failure at the sink, after the file exists' => [static function (self $test): void { $test->tree->writeFailure = new \RuntimeException('native sink failure'); }, WorkflowStep::UNCERTAIN],
		];
	}

	/**
	 * The step classifies a failure as mail_save_attachment answers it to the user, with the same sentence: an error
	 * of the tool is failed and an uncertain receipt is uncertain, before any write as at the sink.
	 *
	 * @param \Closure(self): void $arrange the failure to provoke
	 * @param string $expected the classification of the step
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('classifiedLikeTheOriginalTool')]
	public function testAFailureIsClassifiedAsTheOriginalToolAnswersIt(\Closure $arrange, string $expected): void {
		$arrange($this);
		try {
			$receipt = json_decode($this->original->handle(['message_id' => 7, 'attachment_id' => '2', 'path' => '/Documentos/saved.pdf', 'confirm' => true], 'alice')['content'][0]['text'], true);
			[$status, $message] = [$receipt['outcome'] === 'uncertain' ? WorkflowStep::UNCERTAIN : WorkflowStep::APPLIED, $receipt['message']];
		} catch (ToolFailure $e) {
			[$status, $message] = [WorkflowStep::FAILED, $e->getMessage()];
		}
		$this->setUp();
		$arrange($this);

		$step = $this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false);

		self::assertSame($expected, $status, 'mail_save_attachment itself');
		self::assertSame([$status, $message], [$step->status, $step->message]);
		self::assertStringNotContainsString('delete', implode(' ', $this->tree->ops));
		if ($expected === WorkflowStep::UNCERTAIN) {
			// The file the failed write left is named by its path, and by its id only when the write is known to be ours.
			self::assertSame('/Documentos/saved.pdf', $step->ids['path']);
			self::assertContains($step->ids['fileId'] ?? null, [null, $this->tree->nodes[self::TARGET]['id']]);
			self::assertStringContainsString('/Documentos/saved.pdf', (string)$step->message);
		}
	}

	/** A policy unreadable before the file exists leaves both the tool and the step as the same 503, with nothing created. @return void */
	public function testAPolicyUnreadableBeforeTheFileIsThe503OfBoth(): void {
		$this->tree->beforeCreate = static fn () => throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('policy');
		foreach ([fn () => $this->original->handle(['message_id' => 7, 'attachment_id' => '2', 'path' => '/Documentos/saved.pdf', 'confirm' => true], 'alice'),
			fn () => $this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false)] as $call) {
			try {
				$call();
				self::fail('A policy unreadable before the file must leave as a 503');
			} catch (\OCA\Mcp\Access\Policy\PolicyUnavailable) {
			}
		}
		self::assertArrayNotHasKey(self::TARGET, $this->tree->nodes);
	}
}
