<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Discovery;

use OCA\Forms\Db\Form;
use OCP\IUser;

/** Native owned plus shared discovery, including permitAll forms not shown on the sidebar. */
final class FormCandidates {
	/** Receives per-item native authorization.
	 * @param FormReauthorizer $authorization Rechecks the current actor on each entity.
	 */
	public function __construct(private FormReauthorizer $authorization) {
	}

	/** Discovers only through native read APIs, never getPartialFormArray.
	 * @param object $mapper FormMapper with explicit owner argument.
	 * @param object $service Proven FormsService.
	 * @param IUser $actor Current actor.
	 * @return list<Form> Reauthorized native candidates.
	 */
	public function all(object $mapper, object $service, IUser $actor): array {
		return $this->authorization->allowed($service, array_merge($mapper->findAllByOwnerId($actor->getUID()), $service->getSharedForms($actor, false)));
	}
}
