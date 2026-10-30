<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

/** Only replaces the PollService reference with a transparent native-call counter for runtime evidence. */
final class PollsProbeContainer implements \Psr\Container\ContainerInterface {
	/**
	 * Receives the native call observer.
	 * @param PollsCountingService $counter real service wrapper
	 * @return void
	 */
	public function __construct(private PollsCountingService $counter) {
	}
	/**
	 * Resolves real native dependencies, with only one transparent instrumentation point.
	 * @param string $id native class
	 * @return mixed native dependency
	 */
	public function get(string $id): mixed {
		return $id===\OCA\Mcp\Tools\Polls\PollsSurface::POLLS?$this->counter:\OCP\Server::get($id);
	}
	/**
	 * Native class availability.
	 * @param string $id class
	 * @return bool available
	 */
	public function has(string $id): bool {
		return class_exists($id) || interface_exists($id);
	}
}
