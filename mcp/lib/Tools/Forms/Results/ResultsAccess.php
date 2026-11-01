<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;
use OCA\Mcp\Tools\Forms\FormsServices;
use OCA\Mcp\Tools\Forms\FormsMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Uses the proven FormsService instance for native RESULTS access, never cosmetic flags. */
class ResultsAccess {
	/** Receives the existing per-actor app/session/probe boundary.
	 * @param FormsServices $forms Native service admission.
	 */
	public function __construct(private FormsServices $forms) {
	}
	/** Admits all or historical own results before any FO2 data lookup.
	 * @param string $uid Authenticated actor.
	 * @param int $formId Requested form.
	 * @return array{service:object,form:Form,mode:string,version:string} Proven access.
	 */
	public function open(string $uid, int $formId): array {
		$context = $this->forms->open($uid);
		$form = $context['service']->getFormIfAllowed($formId, Constants::PERMISSION_RESULTS);
		if (!$form instanceof Form || (int)$form->getId() !== $formId) { throw new ToolFailure(FormsMessages::notFound()); }
		$mode = in_array(Constants::PERMISSION_RESULTS, $context['service']->getPermissions($form), true) ? 'all' : 'own';
		if ($mode === 'own' && $uid === '0') { throw new ToolFailure(ResultsMessages::ownLimit()); }
		return ['service' => $context['service'], 'form' => $form, 'mode' => $mode, 'version' => $context['version']];
	}
}
