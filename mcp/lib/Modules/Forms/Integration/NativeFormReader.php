<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;

/** Applies native SUBMIT-mode access and requires the native entity before projection. */
final class NativeFormReader {
	/** Prevents construction of the native entity check. */
	private function __construct() {
	}

	/**
	 * @param object $service the identity-proven native service
	 * @param mixed $formId the original schema-validated form identity
	 * @return Form authorized native entity
	 */
	public static function read(object $service, mixed $formId): Form {
		$form = $service->getFormIfAllowed($formId, Constants::PERMISSION_SUBMIT);
		if (!$form instanceof Form) { throw new \UnexpectedValueException('Expected native Form'); }
		return $form;
	}
}
