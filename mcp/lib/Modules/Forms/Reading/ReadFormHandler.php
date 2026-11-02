<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reading;

use OCA\Mcp\Modules\Forms\Integration\NativeFormReader;
use OCA\Mcp\Modules\Forms\Integration\FormsServices;
use OCA\Mcp\Modules\Forms\Integration\FormsCalls;
use OCA\Mcp\Modules\Forms\Presentation\FormsResponseBudget;
use OCA\Mcp\Modules\Forms\Integration\FormFieldWhitelist;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/** Reads an authorized native Form entity, never submissions or a partial-array permission surrogate. */
final class ReadFormHandler {
	/** Receives the proven service admission and the unique whitelist.
	 * @param FormsServices $forms Enabled and identity-proven services.
	 * @param FormFieldWhitelist $shape Respondent/editor whitelist.
	 */
	public function __construct(private FormsServices $forms, private FormFieldWhitelist $shape) {
	}

	/** Reads by id with native SUBMIT-mode access, which does not require the literal submit permission on a share.
	 * @param array<string,mixed> $arguments Validated form_id.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> MCP JSON untrusted structure.
	 */
	public function handle(array $arguments, string $uid): array {
		return FormsCalls::run(function () use ($arguments, $uid): array {
			$context = $this->forms->open($uid);
			$form = NativeFormReader::read($context['service'], $arguments['form_id']);
			return ToolResult::json(FormsResponseBudget::envelope(['form' => $this->shape->of($form, $context['service'], true)]));
		});
	}
}
