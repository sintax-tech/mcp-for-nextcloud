<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use Closure;

/**
 * One critical write flow of a module, as the effect contract runs it: the tool, the arguments of its plan, the grant
 * that admits it, how to observe its effect and, where the module has them, how to hide its target and how to change
 * the world between the plan and the confirmation.
 *
 * A step that does not apply to the module (no file to hide, no plan fingerprint) is declared with the reason, so the
 * golden says it was considered and why it is absent.
 */
final class CriticalFlow {
	/** @var (Closure(): void)|null */
	public ?Closure $hide = null;
	/** @var (Closure(): void)|null */
	public ?Closure $unhide = null;
	public string $hideSkipped = '';
	/** @var (Closure(): void)|null */
	public ?Closure $changePlan = null;
	public string $changeSkipped = '';
	/** @var (Closure(mixed, array<string, mixed>): array<string, mixed>)|null */
	public ?Closure $confirmArguments = null;
	/** @var (Closure(): array<string, string>)|null */
	private ?Closure $literals = null;

	/**
	 * @param string $name flow name, part of the golden file name
	 * @param string $tool tool under test
	 * @param string $module grant module of the tool
	 * @param string $operation grant operation revoked by the no-grant step
	 * @param array<string, mixed>|Closure(): array<string, mixed> $arguments arguments of the plan (without confirm);
	 *     a closure when they depend on the current state (the ETag of the target)
	 * @param Closure(): mixed $state the observable effect: what the fakes hold now (files, rows, calls)
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $tool,
		public readonly string $module,
		public readonly string $operation,
		private readonly array|Closure $arguments,
		public readonly Closure $state,
	) {
	}

	/** @return array<string, mixed> the plan arguments as of now */
	public function arguments(): array {
		return $this->arguments instanceof Closure ? ($this->arguments)() : $this->arguments;
	}

	/**
	 * @param Closure(): void $hide makes the target of the flow invisible to MCP (hidden tag, excluded folder)
	 * @param Closure(): void $unhide undoes it
	 * @return self this flow
	 */
	public function hiding(Closure $hide, Closure $unhide): self {
		$this->hide = $hide;
		$this->unhide = $unhide;
		return $this;
	}

	/**
	 * @param string $reason why the module has no visibility refusal on this flow
	 * @return self this flow
	 */
	public function withoutVisibility(string $reason): self {
		$this->hideSkipped = $reason;
		return $this;
	}

	/**
	 * @param Closure(): void $change changes what the plan showed, between the plan and its confirmation
	 * @return self this flow
	 */
	public function changing(Closure $change): self {
		$this->changePlan = $change;
		return $this;
	}

	/**
	 * @param string $reason why the module answers no PlanChanged on this flow
	 * @return self this flow
	 */
	public function withoutPlanChanged(string $reason): self {
		$this->changeSkipped = $reason;
		return $this;
	}

	/**
	 * @param Closure(mixed, array<string, mixed>): array<string, mixed> $build arguments of the confirmed call from the
	 *     body of its plan and the plan arguments; the default adds confirm: true and the plan_state of the plan
	 * @return self this flow
	 */
	public function confirmingWith(Closure $build): self {
		$this->confirmArguments = $build;
		return $this;
	}

	/**
	 * Values of this flow that are random or wall-clock by nature (a UID the code draws, a stamp of the real clock),
	 * read from what the fakes really stored at the end of the run. They are replaced in the transcript only, never
	 * in what the code under test stored or read back; the test asserts their shape before normalization.
	 *
	 * @param Closure(): array<string, string> $literals original value => placeholder
	 * @return self this flow
	 */
	public function normalizing(Closure $literals): self {
		$this->literals = $literals;
		return $this;
	}

	/** @return array<string, string> the literal replacements of this flow; none by default */
	public function literals(): array {
		return $this->literals === null ? [] : ($this->literals)();
	}

	/**
	 * @param mixed $planBody JSON-RPC body of the plan (objects as stdClass)
	 * @return array<string, mixed> the arguments that confirm exactly that plan
	 */
	public function confirmed(mixed $planBody): array {
		if ($this->confirmArguments !== null) {
			return ($this->confirmArguments)($planBody, $this->arguments());
		}
		$state = $planBody->result->structuredContent->plan_state ?? null;
		return $this->arguments() + ['confirm' => true] + (is_string($state) ? ['plan_state' => $state] : []);
	}
}
