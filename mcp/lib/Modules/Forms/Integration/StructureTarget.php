<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;
use OCA\Forms\Exception\NoSuchFormException;
use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/**
 * Resolves the target form with native EDIT, without revealing whether an unreadable form exists.
 *
 * A missing form and a form without native read access give the same not-found answer. Forbidden is reserved for a form the
 * actor can read natively (SUBMIT access, the read mode of forms_read_form) but cannot edit.
 */
class StructureTarget {
	/**
	 * @param EditContext $context admitted native graph
	 * @param int $formId requested form
	 * @return Form the form, natively editable by the actor
	 * @throws ToolFailure not found, forbidden or refused
	 */
	public function form(EditContext $context, int $formId): Form {
		$service = $context->service;
		try {
			$form = $service->getFormIfAllowed($formId, Constants::PERMISSION_EDIT);
		} catch (NoSuchFormException $error) {
			throw new ToolFailure($this->readable($service, $formId) ? CommonMessages::forbidden() : CommonMessages::notFound(), 0, $error);
		}
		if (!$form instanceof Form || $form->getId() !== $formId) {
			throw new ToolFailure(StructureMessages::refused());
		}
		return $form;
	}

	/**
	 * @param object $service native FormsService
	 * @param int $formId form that failed the EDIT check
	 * @return bool whether the actor can read the form natively
	 */
	private function readable(object $service, int $formId): bool {
		try {
			return $service->getFormIfAllowed($formId, Constants::PERMISSION_SUBMIT) instanceof Form;
		} catch (\Throwable) {
			return false;
		}
	}
}
