<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms;

use OCA\Forms\Constants;
use OCA\Forms\Db\Form;
use OCA\Mcp\Tools\ToolFailure;
use OCP\IUserSession;

/** Proves the null/actor captured identity within the authenticated MCP endpoint invariant, never by mutating DI. */
final class FormsIdentityProbe {
	/** Receives the request session without changing it.
	 * @param IUserSession $session Current authenticated session.
	 */
	public function __construct(private IUserSession $session) {
	}

	/** Checks the actual service instance before any form, question, share or submission lookup.
	 * A divergent third actor is outside the endpoint invariant; its test requires no orphan shares at sentinel id0.
	 * @param object $service The resolved native FormsService, including a lazy object.
	 * @param string $uid Authenticated actor.
	 * @return void
	 * @throws ToolFailure When identity cannot be established, including probe exceptions.
	 */
	public function assertActor(object $service, string $uid): void {
		try {
			if ($uid === '' || $this->session->getUser()?->getUID() !== $uid) { throw new \RuntimeException('Session mismatch'); }
			$form = new Form();
			$form->setId(0);
			$form->setOwnerId($uid);
			$form->setAccess(['permitAllUsers' => false, 'showToAllUsers' => false]);
			if ($service->getPermissions($form) !== Constants::PERMISSION_ALL) { throw new \RuntimeException('Captured identity mismatch'); }
		} catch (\Throwable $error) {
			throw new ToolFailure(FormsMessages::unavailable(), 0, $error);
		}
	}
}
