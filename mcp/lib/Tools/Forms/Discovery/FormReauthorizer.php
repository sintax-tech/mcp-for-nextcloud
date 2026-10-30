<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Discovery;

use OCA\Forms\Db\Form;

/** Reauthorizes real entities with the native service, without inferring submit permission from string membership. */
final class FormReauthorizer {
	/** Drops candidates the current native actor can no longer access.
	 * @param object $service Proven FormsService.
	 * @param array<mixed> $candidates Native candidate entities.
	 * @return list<Form> Unique authorized entities.
	 */
	public function allowed(object $service, array $candidates): array {
		$allowed = [];
		foreach ($candidates as $form) {
			if (!$form instanceof Form) { throw new \UnexpectedValueException('Expected native Form'); }
			if ($service->hasUserAccess($form)) { $allowed[(int)$form->getId()] = $form; }
		}
		return array_values($allowed);
	}
}
