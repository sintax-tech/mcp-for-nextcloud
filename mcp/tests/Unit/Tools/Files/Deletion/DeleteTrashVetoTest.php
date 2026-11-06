<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Files\Deletion;

use OCA\Mcp\Modules\Files\Deletion\DeleteTrashVeto;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The observer can only veto the admitted node and never remains installed after the attempt. */
final class DeleteTrashVetoTest extends TestCase {
	/** @return iterable<string, array{bool, bool}> Event trash decision and whether its node matches. */
	public static function cases(): iterable {
		yield 'allowed' => [true, true];
		yield 'opt-out' => [false, true];
		yield 'different node' => [false, false];
	}

	/**
	 * @param bool $enabled Whether the native event still allows the trash bin.
	 * @param bool $same Whether the event names this admitted node.
	 * @return void Registration, veto and finally cleanup are request-scoped.
	 */
	#[DataProvider('cases')]
	public function testBoundedVetoAndRemoval(bool $enabled, bool $same): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(2);
		$file->method('getPath')->willReturn('/alice/files/a');
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($same ? 2 : 3);
		$node->method('getPath')->willReturn('/owner/files/original/a');
		$event = new class($node, $enabled) extends Event {
			/** @param File $node Event source. @param bool $enabled Native trash decision. */
			public function __construct(private File $node, private bool $enabled) {
				parent::__construct();
			}
			/** @return File Event source. */
			public function getNode(): File { return $this->node; }
			/** @return bool Native trash decision. */
			public function shouldMoveToTrashBin(): bool { return $this->enabled; }
		};
		$listeners = [];
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects(self::exactly(2))->method('addListener')->willReturnCallback(static function ($name, $listener, $priority) use (&$listeners): void {
			self::assertSame(PHP_INT_MIN, $priority);
			$listeners[$name] = $listener;
		});
		$events->expects(self::exactly(2))->method('removeListener')->willReturnCallback(static function ($name, $listener) use (&$listeners): void {
			self::assertArrayHasKey($name, $listeners);
			self::assertSame($listeners[$name], $listener);
			unset($listeners[$name]);
		});
		$calls = 0;
		try {
			(new DeleteTrashVeto($events))->run($file, static function () use (&$listeners, $event, &$calls): void {
				foreach ($listeners as $listener) { $listener($event); }
				$calls++;
			});
		} catch (AbortedEventException) {
			self::assertTrue($same && !$enabled);
		}
		self::assertSame($same && !$enabled ? 0 : 1, $calls);
		self::assertSame([], $listeners);
	}
}
