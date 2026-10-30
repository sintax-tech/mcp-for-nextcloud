<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail\Plans;

use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolModule;

/**
 * The Mail module with the plan text of one write taken away, as a module that forgot it would be: everything else is
 * the real module. MailPlanRendererTest shows the readability contract rejects it.
 */
final class PlanlessMailModule implements ToolModule, PreviewsWrites, RendersPlans {
	/**
	 * Receives the real module and the write left without its text.
	 *
	 * @param MailModule $module the real module
	 * @param string $planless the write whose plan falls back to the generic one
	 * @return void
	 */
	public function __construct(
		private MailModule $module,
		private string $planless,
	) {
	}

	/**
	 * The definitions of the real module.
	 *
	 * @return list<array<string, mixed>> the definitions
	 */
	public function definitions(): array {
		return $this->module->definitions();
	}

	/**
	 * A call of the real module.
	 *
	 * @param string $name tool name
	 * @param array<string, mixed> $arguments arguments
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return $this->module->call($name, $arguments, $userId);
	}

	/**
	 * The plan of the real module.
	 *
	 * @param string $name tool name
	 * @param array<string, mixed> $arguments arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return $this->module->preview($name, $arguments, $userId);
	}

	/**
	 * The text of the real module, except for the planless write.
	 *
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan the plan
	 * @return string|null Markdown body, or null for the generic one
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return $tool === $this->planless ? null : $this->module->renderPlan($tool, $plan);
	}
}
