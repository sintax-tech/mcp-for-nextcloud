<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Handler;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;
use OCA\Mcp\Tools\Forms\FormsServices;
use OCA\Mcp\Tools\Forms\FormsCalls;
use OCA\Mcp\Tools\Forms\Structure\FormsResponseBudget;
use OCA\Mcp\Tools\Forms\Structure\FormFieldWhitelist;
use OCA\Mcp\Tools\ToolResult;

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
			$form = $context['service']->getFormIfAllowed($arguments['form_id'], Constants::PERMISSION_SUBMIT);
			if (!$form instanceof Form) { throw new \UnexpectedValueException('Expected native Form'); }
			return ToolResult::json(FormsResponseBudget::envelope(['form' => $this->shape->of($form, $context['service'], true)]));
		});
	}
}
