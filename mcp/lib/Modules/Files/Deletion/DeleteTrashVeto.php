<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Deletion;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Node;

/** Vetoes an opt-out observed at the native event, without pretending to control later backend failures. */
final class DeleteTrashVeto {
	/** @param IEventDispatcher $events Native request-local dispatcher. */
	public function __construct(private IEventDispatcher $events) {
	}

	/**
	 * The app emits both typed and legacy events. Last-priority observers refuse an already disabled trash bin;
	 * a later listener or transfer failure can still change the outcome. No global context survives this call.
	 * @param Node $file Admitted file; only its positive global file ID is vetoed, across actor/owner aliases; this never grants access.
	 * @param callable():void $delete Native Node call with the existing lock helper around it.
	 * @return void
	 */
	public function run(Node $file, callable $delete): void {
		$id = $file->getId();
		$listener = static function (Event $event) use ($id): void {
			$node = $event->getNode();
			if ((int)$id > 0 && $node->getId() === $id && !$event->shouldMoveToTrashBin()) {
				throw new AbortedEventException('MCP deletion refuses an observed trash opt-out');
			}
		};
		$names = ['OCA\\Files_Trashbin\\Events\\MoveToTrashEvent', 'OCA\\Files_Trashbin::moveToTrash'];
		try {
			foreach ($names as $name) {
				$this->events->addListener($name, $listener, PHP_INT_MIN);
			}
			$delete();
		} finally {
			foreach ($names as $name) {
				$this->events->removeListener($name, $listener);
			}
		}
	}
}
