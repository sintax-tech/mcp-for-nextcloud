<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * mail_save_attachment and its port when the destination is hidden in any phase after newFile and something throws:
 * neither names the path, the name or the id of the file, which stays where it was written.
 */
final class MailAttachmentSaveOpacityTest extends MailAttachmentSaveStepsTestCase {
	/** @return array<string, array{\Closure(self): void}> each phase of the save after newFile, where Documentos is hidden and something throws */
	public static function hideAndThrow(): array {
		$hide = static function (self $test): void { $test->items = [(string)$test->tree->nodes['/alice/files/Outros']['id']]; };
		$once = static function (self $test) use ($hide): void {
			$test->tree->beforeGet = static function () use ($test, $hide): void { $test->tree->beforeGet = null; $hide($test); throw new \RuntimeException('read failed'); };
		};
		return [
			'inside newFile' => [static function (self $test) use ($hide): void { $test->tree->afterCreate = static function () use ($test, $hide): void {
				$test->tree->afterCreate = null; $hide($test); throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed'); }; }],
			'assertVisible after newFile' => [static function (self $test) use ($hide): void { $test->tree->afterCreate = static function () use ($test, $hide): void {
				$test->tree->afterCreate = null; $hide($test); }; }],
			'read again before the content' => [static function (self $test) use ($once): void { $test->tree->afterCreate = static function () use ($test, $once): void {
				$test->tree->afterCreate = null; $once($test); }; }],
			'putContent' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \RuntimeException('post-write hook'); }; }],
			'putContent locked, the lock naming the file' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \OCP\Lock\ManuallyLockedException('/alice/files/Documentos/saved.pdf', null, 'files_lock/x', 'text', -1); }; }],
			'putContent refused, the refusal naming the file' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('The file /alice/files/Documentos/saved.pdf was refused.'); }; }],
			'read-back of the destination' => [static function (self $test) use ($once): void { $test->tree->afterWrite = static function () use ($test, $once): void {
				$test->tree->afterWrite = null; $once($test); }; }],
		];
	}

	/**
	 * mail_save_attachment and its port, in every phase that hides the folder and throws, answer without the path, the
	 * name or the id of the file, which stays in place.
	 *
	 * @param \Closure(self): void $arrange the phase that hides Documentos and throws
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('hideAndThrow')]
	public function testAFileHiddenInAnyPhaseIsNeverNamed(\Closure $arrange): void {
		$arrange($this);
		try {
			$answer = json_encode($this->original->handle(['message_id' => 7, 'attachment_id' => '2', 'path' => '/Documentos/saved.pdf', 'confirm' => true], 'alice'));
		} catch (ToolFailure $e) {
			$answer = $e->getMessage();
		}
		self::assertStringNotContainsString('saved.pdf', $answer);
		self::assertStringNotContainsString('"fileId"', $answer);
		self::assertArrayHasKey(self::TARGET, $this->tree->nodes, 'nothing is deleted');

		$this->setUp();
		$arrange($this);
		$step = $this->steps->save('alice', 7, '2', '/Documentos/saved.pdf', false);
		self::assertSame([], $step->ids);
		self::assertStringNotContainsString('saved.pdf', json_encode([$step->message, $step->result]));
		self::assertStringNotContainsString('"fileId"', json_encode($step->result));
	}
}
