<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Modules\Polls\PollsModule;
use OCA\Mcp\Modules\Polls\PollsToolCatalog;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

/** Wires the real published mutation tools over a stateful native port double. */
final class PollsMutationModule {
	/**
	 * @param TestCase $test Running test owning the plan-state secret.
	 * @param PollsMutationScene $scene Stateful native port.
	 * @return PollsModule Real catalog with real handler and preview classes.
	 * @throws \ReflectionException If a tool dependency no longer follows the module contract.
	 */
	public static function build(TestCase $test, PollsMutationScene $scene): PollsModule {
		$flow = PollsMutationCases::flow($test, $scene);
		$tools = [];
		foreach (PollsToolCatalog::TOOLS as $class) {
			if (!isset(PollsWriteSchema::OPERATIONS[$class::definition()->name])) {
				continue;
			}
			$arguments = [];
			foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
				$worker = $parameter->getType()->getName();
				$arguments[] = new $worker(str_ends_with($worker, 'Preview') ? $flow->preview : $flow->effect);
			}
			$tools[$class] = new $class(...$arguments);
		}
		return new PollsModule(
			new class($tools) implements ContainerInterface {
				/**
				 * @param array<class-string,object> $tools Constructed tools.
				 * @return void
				 */
				public function __construct(private array $tools) {
				}
				/**
				 * @param string $id Tool class.
				 * @return object Real tool.
				 * @throws \LogicException If an unrelated legacy tool is requested.
				 */
				public function get(string $id): mixed {
					return $this->tools[$id] ?? throw new \LogicException('Unwired mutation fixture tool');
				}
				/**
				 * @param string $id Tool class.
				 * @return bool Whether this fixture wires the tool.
				 * @throws \Throwable If validation or a called collaborator fails.
				 */
				public function has(string $id): bool {
					return isset($this->tools[$id]);
				}
			}
		);
	}
}
