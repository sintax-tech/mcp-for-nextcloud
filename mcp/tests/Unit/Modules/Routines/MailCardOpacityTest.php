<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Platform\Workflows\WorkflowStep;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * routines_create_mail_card when the file is hidden or cannot be read again after Nextcloud created it: the receipt
 * never names a path, a name or an id the rules hide, the file stays where it was written and no card starts.
 */
final class MailCardOpacityTest extends MailCardWorld {
	/** @return array<string, array{\Closure(self): void, bool}> what happens inside newFile after the node exists, and whether the path may be named */
	public static function newFileFailures(): array {
		return [
			'the name is visible' => [static function (self $test): void {}, true],
			'the name is hidden' => [static function (self $test): void { $test->items = [(string)$test->tree->nodes['/alice/files/Outros']['id']]; }, false],
			'the name cannot be read again' => [static function (self $test): void { $test->tree->beforeGet = static fn () => throw new \RuntimeException('storage'); }, false],
		];
	}

	/**
	 * newFile throws after the node exists: the name is read again, and a file that may be there is named only when
	 * alice may see it, without an id that would claim it; no card starts and nothing is deleted.
	 *
	 * @param \Closure(self): void $then what else happens inside newFile
	 * @param bool $named whether the receipt names the path
	 * @return void
	 */
	#[DataProvider('newFileFailures')]
	public function testAFailureInsideNewFileIsReconciledWithTheName(\Closure $then, bool $named): void {
		$this->tree->afterCreate = function () use ($then): void {
			$this->tree->afterCreate = null;
			$then($this);
			throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed');
		};

		[$receipt, $error] = $this->planAndConfirm();

		self::assertFalse($error);
		self::assertSame([WorkflowStep::UNCERTAIN, 'not_started'], array_column($receipt['steps'], 'status'));
		self::assertSame($named ? ['path' => '/Documentos/pedido.pdf'] : [], $receipt['steps'][0]['ids']);
		self::assertSame($named, str_contains(json_encode($receipt), 'pedido.pdf'), 'a hidden or unreadable name is never shown');
		self::assertArrayHasKey(self::TARGET, $this->tree->nodes);
		self::assertSame([], $this->created);
	}

	/** The destination hidden at the read-back answers the opaque refusal of a hidden item: no path, no id, no card. @return void */
	public function testADestinationHiddenAtTheReadBackIsOpaque(): void {
		$this->tree->afterWrite = function (): void {
			$this->items = [(string)$this->tree->nodes['/alice/files/Outros']['id']];
		};

		[$receipt] = $this->planAndConfirm();

		self::assertSame([WorkflowStep::FAILED, 'not_started'], array_column($receipt['steps'], 'status'));
		self::assertSame([], $receipt['steps'][0]['ids']);
		self::assertStringNotContainsString('pedido.pdf', json_encode($receipt));
		self::assertStringNotContainsString((string)$this->tree->nodes[self::TARGET]['id'], json_encode($receipt['steps'][0]));
		self::assertSame([], $this->created);
	}

	/** @return array<string, array{\Closure(self): void}> each phase of the save after newFile, where Documentos is hidden and something throws */
	public static function hideAndThrow(): array {
		$hide = static function (self $test): void { $test->items = [(string)$test->tree->nodes['/alice/files/Outros']['id']]; };
		$once = static function (self $test, \Closure $then): void {
			$test->tree->beforeGet = static function () use ($test, $then): void { $test->tree->beforeGet = null; $then($test); throw new \RuntimeException('read failed'); };
		};
		return [
			'inside newFile' => [static function (self $test) use ($hide): void { $test->tree->afterCreate = static function () use ($test, $hide): void {
				$test->tree->afterCreate = null; $hide($test); throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('hook failed'); }; }],
			'assertVisible after newFile' => [static function (self $test) use ($hide): void { $test->tree->afterCreate = static function () use ($test, $hide): void {
				$test->tree->afterCreate = null; $hide($test); }; }],
			'read again before the content' => [static function (self $test) use ($hide, $once): void { $test->tree->afterCreate = static function () use ($test, $hide, $once): void {
				$test->tree->afterCreate = null; $once($test, $hide); }; }],
			'putContent' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \RuntimeException('post-write hook'); }; }],
			'putContent locked, the lock naming the file' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \OCP\Lock\ManuallyLockedException('/alice/files/Documentos/pedido.pdf', null, 'files_lock/x', 'text', -1); }; }],
			'putContent refused, the refusal naming the file' => [static function (self $test) use ($hide): void { $test->tree->afterWrite = static function () use ($test, $hide): void {
				$test->tree->afterWrite = null; $hide($test); throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('The file /alice/files/Documentos/pedido.pdf was refused.'); }; }],
			'read-back of the destination' => [static function (self $test) use ($hide, $once): void { $test->tree->afterWrite = static function () use ($test, $hide, $once): void {
				$test->tree->afterWrite = null; $once($test, $hide); }; }],
		];
	}

	/**
	 * Whichever phase hides the folder and throws, the receipt names nothing: no path, no file name, no id. The file
	 * stays where it was written and no card starts.
	 *
	 * @param \Closure(self): void $arrange the phase that hides Documentos and throws
	 * @return void
	 */
	#[DataProvider('hideAndThrow')]
	public function testAFileHiddenInAnyPhaseIsNeverNamed(\Closure $arrange): void {
		$arrange($this);

		[$receipt] = $this->planAndConfirm();

		$json = json_encode($receipt);
		self::assertSame([], $receipt['steps'][0]['ids']);
		self::assertStringNotContainsString('pedido.pdf', $json);
		self::assertStringNotContainsString('Documentos', $json);
		self::assertStringNotContainsString('"fileId"', $json);
		self::assertContains($receipt['steps'][0]['status'], [WorkflowStep::FAILED, WorkflowStep::UNCERTAIN]);
		self::assertSame('not_started', $receipt['steps'][1]['status']);
		self::assertArrayHasKey(self::TARGET, $this->tree->nodes, 'nothing is deleted');
		self::assertSame([], $this->created);
	}
}
